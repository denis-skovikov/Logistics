<?php

namespace App\Services;

use App\Enums\CacheStatus;
use Illuminate\Support\Facades\Cache;

class SlotCacheService
{
    private string $cacheKey;
    private int $cacheTtl;
    private int $lockTimeout;

    public function __construct()
    {
        $this->cacheKey = config('slots.cache_key');
        $this->cacheTtl = config('slots.cache_ttl');
        $this->lockTimeout = config('slots.lock_timeout');
    }

    /**
     * Получить данные из кеша или выполнить callback с защитой от stampede.
     * Возвращает ['data' => array, 'cache' => string, 'query_time' => string|float]
     */
    public function remember(callable $queryCallback): array
    {
        $cached = Cache::get($this->cacheKey);

        if ($cached !== null) {
            return [
                'data' => $cached,
                'cache' => CacheStatus::Hit->value,
                'query_time' => CacheStatus::CacheQueryTime->value,
            ];
        }

        $lock = Cache::lock($this->cacheKey . ':lock', $this->lockTimeout);

        try {
            $lock->block($this->lockTimeout);

            // Повторная проверка после получения лока
            $cached = Cache::get($this->cacheKey);
            if ($cached !== null) {
                return [
                    'data' => $cached,
                    'cache' => CacheStatus::Hit->value,
                    'query_time' => CacheStatus::CacheQueryTime->value,
                ];
            }

            $start = hrtime(true);
            $data = $queryCallback();
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            Cache::put($this->cacheKey, $data, $this->cacheTtl);

            return [
                'data' => $data,
                'cache' => CacheStatus::Miss->value,
                'query_time' => round($elapsed, 2),
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Инвалидировать кеш слотов.
     */
    public function invalidate(): void
    {
        Cache::forget($this->cacheKey);
    }
}
