<?php

namespace App\Services;

interface SlotServiceInterface
{
    /**
     * Получить список слотов.
     * Возвращает массив: ['data' => [...], 'cache' => 'HIT'|'MISS', 'query_time' => string]
     */
    public function getSlots(): array;

    /**
     * Создать холд на слот.
     */
    public function createHold(int $slotId, string $idempotencyKey): array;

    /**
     * Подтвердить холд.
     */
    public function confirmHold(int $holdId): array;

    /**
     * Отменить холд.
     */
    public function cancelHold(int $holdId): array;
}
