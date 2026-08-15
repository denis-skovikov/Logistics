<?php

return [
    // Реализация сервиса: denormalized или dynamic
    'provider' => env('SLOTS_PROVIDER', 'denormalized'),

    // Ключ кеша для списка слотов
    'cache_key' => env('SLOTS_CACHE_KEY', 'slots:all'),

    // Время хранения кеша в секундах
    'cache_ttl' => (int) env('SLOTS_CACHE_TTL', 15),

    // Время ожидания лока (cache stampede protection) в секундах
    'lock_timeout' => (int) env('SLOTS_LOCK_TIMEOUT', 5),

    // Время жизни холда в минутах
    'hold_ttl' => (int) env('SLOTS_HOLD_TTL', 5),
];
