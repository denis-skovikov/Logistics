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
     * Возвращает данные созданного холда.
     *
     * @throws \App\Exceptions\SlotNotFoundException
     * @throws \App\Exceptions\CapacityExhaustedException
     */
    public function createHold(int $slotId, string $idempotencyKey): array;

    /**
     * Подтвердить холд.
     * Возвращает данные подтверждённого холда.
     *
     * @throws \App\Exceptions\HoldNotFoundException
     * @throws \App\Exceptions\HoldConflictException
     * @throws \App\Exceptions\HoldExpiredException
     */
    public function confirmHold(int $holdId): array;

    /**
     * Отменить холд.
     * Возвращает данные отменённого холда.
     *
     * @throws \App\Exceptions\HoldNotFoundException
     * @throws \App\Exceptions\HoldConflictException
     */
    public function cancelHold(int $holdId): array;
}
