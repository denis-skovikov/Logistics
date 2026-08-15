<?php

namespace App\Services;

use App\Models\Slot;
use Illuminate\Support\Facades\Cache;

class DenormalizedSlotService implements SlotServiceInterface
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
            $slots = Slot::all(['id', 'capacity', 'remaining'])->map(fn ($slot) => [
                'slot_id' => $slot->id,
                'capacity' => $slot->capacity,
                'remaining' => $slot->remaining,
            ])->toArray();
            $elapsed = (hrtime(true) - $start) / 1_000_000; // в миллисекундах

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
        // TODO: реализация
        return [];
    }

    public function confirmHold(int $holdId): array
    {
        // TODO: реализация
        return [];
    }

    public function cancelHold(int $holdId): array
    {
        // TODO: реализация
        return [];
    }
}
