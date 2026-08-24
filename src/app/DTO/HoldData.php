<?php

namespace App\DTO;

class HoldData
{
    public function __construct(
        public readonly int $holdId,
        public readonly int $slotId,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $confirmedAt = null,
        public readonly ?string $cancelledAt = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'hold_id' => $this->holdId,
            'slot_id' => $this->slotId,
            'status' => $this->status,
            'created_at' => $this->createdAt,
        ];

        if ($this->confirmedAt !== null) {
            $data['confirmed_at'] = $this->confirmedAt;
        }

        if ($this->cancelledAt !== null) {
            $data['cancelled_at'] = $this->cancelledAt;
        }

        return $data;
    }
}
