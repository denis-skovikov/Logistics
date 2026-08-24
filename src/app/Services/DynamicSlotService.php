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

class DynamicSlotService implements SlotServiceInterface
{
    public function __construct(
        private readonly SlotCacheService $cache,
    ) {}

    public function getSlots(): SlotListResult
    {
        $result = $this->cache->remember(function () {
            return DB::table('slots')
                ->leftJoin('holds', function ($join) {
                    $join->on('slots.id', '=', 'holds.slot_id')
                        ->whereIn('holds.status', [HoldStatus::Held->value, HoldStatus::Confirmed->value]);
                })
                ->select(
                    'slots.id as slot_id',
                    'slots.capacity',
                    DB::raw('slots.capacity - COUNT(holds.id) as remaining')
                )
                ->groupBy('slots.id', 'slots.capacity')
                ->get()
                ->map(fn ($slot) => [
                    'slot_id' => $slot->slot_id,
                    'capacity' => $slot->capacity,
                    'remaining' => max(0, $slot->remaining),
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
            // SELECT FOR UPDATE — блокируем строку слота для атомарной проверки
            $locked = DB::table('slots')
                ->where('id', $slotId)
                ->lockForUpdate()
                ->first();

            // Считаем занятые места динамически
            $activeHolds = DB::table('holds')
                ->where('slot_id', $slotId)
                ->whereIn('status', [HoldStatus::Held->value, HoldStatus::Confirmed->value])
                ->count();

            $remaining = $locked->capacity - $activeHolds;

            if ($remaining <= 0) {
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
            // Ленивая очистка: отменяем просроченный холд
            $hold->update(['status' => HoldStatus::Cancelled]);

            $this->cache->invalidate();

            throw new HoldExpiredException($holdId);
        }

        // Подтверждаем холд
        $hold->update(['status' => HoldStatus::Confirmed]);

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

        // Отменяем холд (remaining считается динамически)
        $hold->update(['status' => HoldStatus::Cancelled]);

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
