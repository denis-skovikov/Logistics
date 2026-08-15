<?php

namespace App\Services;

use App\Enums\HoldStatus;
use App\Models\Slot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DynamicSlotService implements SlotServiceInterface
{
    public function getSlots(): array
    {
        $cacheKey = config('slots.cache_key');
        $cacheTtl = config('slots.cache_ttl');
        $lockTimeout = config('slots.lock_timeout');

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return [
                'data' => $cached,
                'cache' => 'HIT',
                'query_time' => 'Cache',
            ];
        }

        // Защита от cache stampede через atomic lock
        $lock = Cache::lock($cacheKey . ':lock', $lockTimeout);

        try {
            $lock->block($lockTimeout);

            // Повторная проверка после получения лока
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return [
                    'data' => $cached,
                    'cache' => 'HIT',
                    'query_time' => 'Cache',
                ];
            }

            $start = hrtime(true);

            // remaining считаем динамически через left join
            $slots = DB::table('slots')
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

            $elapsed = (hrtime(true) - $start) / 1_000_000;

            Cache::put($cacheKey, $slots, $cacheTtl);

            return [
                'data' => $slots,
                'cache' => 'MISS',
                'query_time' => round($elapsed, 2),
            ];
        } finally {
            optional($lock)->release();
        }
    }

    public function createHold(int $slotId, string $idempotencyKey): array
    {
        // Идемпотентность: проверяем существующий холд
        $existing = \App\Models\Hold::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return [
                'data' => [
                    'hold_id' => $existing->id,
                    'slot_id' => $existing->slot_id,
                    'status' => $existing->status->value,
                    'created_at' => $existing->created_at->toIso8601String(),
                ],
                'status' => 201,
            ];
        }

        // Проверяем существование слота
        $slot = Slot::find($slotId);
        if (!$slot) {
            return [
                'data' => ['message' => 'Slot not found.'],
                'status' => 404,
            ];
        }

        return DB::transaction(function () use ($slotId, $slot, $idempotencyKey) {
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
                return [
                    'data' => ['message' => 'No available capacity.'],
                    'status' => 409,
                ];
            }

            // Создаём холд
            $hold = \App\Models\Hold::create([
                'slot_id' => $slotId,
                'idempotency_key' => $idempotencyKey,
                'status' => HoldStatus::Held,
            ]);

            // Инвалидируем кеш
            Cache::forget(config('slots.cache_key'));

            return [
                'data' => [
                    'hold_id' => $hold->id,
                    'slot_id' => $hold->slot_id,
                    'status' => $hold->status->value,
                    'created_at' => $hold->created_at->toIso8601String(),
                ],
                'status' => 201,
            ];
        });
    }

    public function confirmHold(int $holdId): array
    {
        $hold = \App\Models\Hold::find($holdId);

        if (!$hold) {
            return [
                'data' => ['message' => 'Hold not found.'],
                'status' => 404,
            ];
        }

        // Можно подтвердить только холд со статусом held
        if ($hold->status !== HoldStatus::Held) {
            return [
                'data' => ['message' => 'Hold cannot be confirmed. Current status: ' . $hold->status->value],
                'status' => 409,
            ];
        }

        // Проверяем просроченность по created_at + hold_ttl
        $holdTtl = config('slots.hold_ttl');
        if ($hold->created_at->addMinutes($holdTtl)->isPast()) {
            // Ленивая очистка: отменяем просроченный холд (remaining не трогаем — считается динамически)
            $hold->update(['status' => HoldStatus::Cancelled]);

            // Инвалидируем кеш
            Cache::forget(config('slots.cache_key'));

            return [
                'data' => ['message' => 'Hold expired and has been cancelled.'],
                'status' => 410,
            ];
        }

        // Подтверждаем холд
        $hold->update(['status' => HoldStatus::Confirmed]);

        return [
            'data' => [
                'hold_id' => $hold->id,
                'slot_id' => $hold->slot_id,
                'status' => $hold->status->value,
                'confirmed_at' => $hold->updated_at->toIso8601String(),
            ],
            'status' => 200,
        ];
    }

    public function cancelHold(int $holdId): array
    {
        $hold = \App\Models\Hold::find($holdId);

        if (!$hold) {
            return [
                'data' => ['message' => 'Hold not found.'],
                'status' => 404,
            ];
        }

        // Отменять можно только холды со статусом held
        if ($hold->status !== HoldStatus::Held) {
            // Идемпотентность: если уже cancelled — возвращаем объект
            if ($hold->status === HoldStatus::Cancelled) {
                return [
                    'data' => [
                        'hold_id' => $hold->id,
                        'slot_id' => $hold->slot_id,
                        'status' => $hold->status->value,
                        'cancelled_at' => $hold->updated_at->toIso8601String(),
                    ],
                    'status' => 200,
                ];
            }

            return [
                'data' => ['message' => 'Hold cannot be cancelled. Current status: ' . $hold->status->value],
                'status' => 409,
            ];
        }

        // Отменяем холд (remaining не трогаем — считается динамически)
        $hold->update(['status' => HoldStatus::Cancelled]);

        // Инвалидируем кеш
        Cache::forget(config('slots.cache_key'));

        return [
            'data' => [
                'hold_id' => $hold->id,
                'slot_id' => $hold->slot_id,
                'status' => $hold->status->value,
                'cancelled_at' => $hold->updated_at->toIso8601String(),
            ],
            'status' => 200,
        ];
    }
}
