<?php

namespace App\Services;

class DynamicSlotService implements SlotServiceInterface
{
    public function getSlots(): array
    {
        // TODO: реализация
        return ['data' => [], 'cache' => 'MISS', 'query_time' => '0'];
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
