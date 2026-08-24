<?php

namespace App\Services;

use App\DTO\HoldData;
use App\DTO\SlotData;
use App\DTO\SlotListResult;
use App\Enums\HoldStatus;
use App\Exceptions\CapacityExhaustedException;
use App\Exceptions\HoldConflictException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\HoldNotFoundException;
use App\Exceptions\SlotNotFoundException;
use App\Models\Hold;
use App\Models\Slot;
use Illuminate\Support\Facades\DB;

class DenormalizedSlotService implements SlotServiceInterface
{
    public function __construct(
        private readonly SlotCacheService $cache,
    ) {}

    public function getSlots(): SlotListResult
    {
        $result = $this->cache->remember(function () {
            return DB::table('slots')
                ->select('id', 'capacity', 'remaining')
                ->get()
                ->map(fn ($slot) => [
                    'slot_id' => $slot->id,
                    'capacity' => $slot->capacity,
                    'remaining' => $slot->remaining,
                ])
                ->toArray();
        });

        $slots = array_map(
            fn ($item) => new SlotData($item['slot_id'], $item['capacity'], $item['remaining']),
            $result['data']
        );

        return new SlotListResult($slots, $result['cache'], $result['query_time']);
    }

    public function createHold(int $slotId, string $idempotencyKey): HoldData
    {
        // Идемпотентность: проверяем существующий холд
        $existing = Hold::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return new HoldData(
                holdId: $existing->id,
                slotId: $existing->slot_id,
                status: $existing->status->value,
                createdAt: $existing->created_at->toIso8601String(),
            );
        }

        // Проверяем существование слота
        $slot = Slot::find($slotId);
        if (!$slot) {
            throw new SlotNotFoundException($slotId);
        }

        return DB::transaction(function () use ($slotId, $idempotencyKey): HoldData {
            // Атомарное уменьшение remaining с защитой от оверсела
            $affected = DB::table('slots')
                ->where('id', $slotId)
                ->where('remaining', '>', 0)
                ->update(['remaining' => DB::raw('remaining - 1'), 'updated_at' => now()]);

            if ($affected === 0) {
                throw new CapacityExhaustedException($slotId);
            }

            // Создаём холд
            $hold = Hold::create([
                'slot_id' => $slotId,
                'idempotency_key' => $idempotencyKey,
                'status' => HoldStatus::Held,
            ]);

            // Инвалидируем кеш
            $this->cache->invalidate();

            return new HoldData(
                holdId: $hold->id,
                slotId: $hold->slot_id,
                status: $hold->status->value,
                createdAt: $hold->created_at->toIso8601String(),
            );
        });
    }

    public function confirmHold(int $holdId): HoldData
    {
        $hold = Hold::find($holdId);

        if (!$hold) {
            throw new HoldNotFoundException($holdId);
        }

        // Можно подтвердить только холд со статусом held
        if ($hold->status !== HoldStatus::Held) {
            throw new HoldConflictException($hold->status->value);
        }

        // Проверяем просроченность по created_at + hold_ttl
        $holdTtl = config('slots.hold_ttl');
        if ($hold->created_at->addMinutes($holdTtl)->isPast()) {
            // Ленивая очистка: отменяем просроченный холд и возвращаем место
            DB::transaction(function () use ($hold) {
                $hold->update(['status' => HoldStatus::Cancelled]);

                DB::table('slots')
                    ->where('id', $hold->slot_id)
                    ->update(['remaining' => DB::raw('remaining + 1'), 'updated_at' => now()]);
            });

            $this->cache->invalidate();

            throw new HoldExpiredException($holdId);
        }

        // Подтверждаем холд
        DB::transaction(function () use ($hold) {
            $hold->update(['status' => HoldStatus::Confirmed]);
        });

        return new HoldData(
            holdId: $hold->id,
            slotId: $hold->slot_id,
            status: $hold->status->value,
            createdAt: $hold->created_at->toIso8601String(),
            confirmedAt: $hold->updated_at->toIso8601String(),
        );
    }

    public function cancelHold(int $holdId): HoldData
    {
        $hold = Hold::find($holdId);

        if (!$hold) {
            throw new HoldNotFoundException($holdId);
        }

        // Идемпотентность: если уже cancelled — возвращаем объект
        if ($hold->status === HoldStatus::Cancelled) {
            return new HoldData(
                holdId: $hold->id,
                slotId: $hold->slot_id,
                status: $hold->status->value,
                createdAt: $hold->created_at->toIso8601String(),
                cancelledAt: $hold->updated_at->toIso8601String(),
            );
        }

        // Отменять можно только холды со статусом held
        if ($hold->status !== HoldStatus::Held) {
            throw new HoldConflictException($hold->status->value);
        }

        DB::transaction(function () use ($hold) {
            $hold->update(['status' => HoldStatus::Cancelled]);

            DB::table('slots')
                ->where('id', $hold->slot_id)
                ->update(['remaining' => DB::raw('remaining + 1'), 'updated_at' => now()]);
        });

        $this->cache->invalidate();

        return new HoldData(
            holdId: $hold->id,
            slotId: $hold->slot_id,
            status: $hold->status->value,
            createdAt: $hold->created_at->toIso8601String(),
            cancelledAt: $hold->updated_at->toIso8601String(),
        );
    }
}
