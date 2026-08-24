<?php

namespace App\Services;

use App\DTO\HoldData;
use App\DTO\SlotListResult;

interface SlotServiceInterface
{
    /**
     * Получить список слотов.
     */
    public function getSlots(): SlotListResult;

    /**
     * Создать холд на слот.
     *
     * @throws \App\Exceptions\SlotNotFoundException
     * @throws \App\Exceptions\CapacityExhaustedException
     */
    public function createHold(int $slotId, string $idempotencyKey): HoldData;

    /**
     * Подтвердить холд.
     *
     * @throws \App\Exceptions\HoldNotFoundException
     * @throws \App\Exceptions\HoldConflictException
     * @throws \App\Exceptions\HoldExpiredException
     */
    public function confirmHold(int $holdId): HoldData;

    /**
     * Отменить холд.
     *
     * @throws \App\Exceptions\HoldNotFoundException
     * @throws \App\Exceptions\HoldConflictException
     */
    public function cancelHold(int $holdId): HoldData;
}
