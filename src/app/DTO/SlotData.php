<?php

namespace App\DTO;

class SlotData
{
    public function __construct(
        public readonly int $slotId,
        public readonly int $capacity,
        public readonly int $remaining,
    ) {}

    public function toArray(): array
    {
        return [
            'slot_id' => $this->slotId,
            'capacity' => $this->capacity,
            'remaining' => $this->remaining,
        ];
    }
}
