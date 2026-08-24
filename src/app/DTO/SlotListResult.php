<?php

namespace App\DTO;

class SlotListResult
{
    /**
     * @param SlotData[] $slots
     */
    public function __construct(
        public readonly array $slots,
        public readonly string $cache,
        public readonly string|float $queryTime,
    ) {}

    public function toArray(): array
    {
        return array_map(fn (SlotData $slot) => $slot->toArray(), $this->slots);
    }
}
