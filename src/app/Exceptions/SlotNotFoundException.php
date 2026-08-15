<?php

namespace App\Exceptions;

use RuntimeException;

class SlotNotFoundException extends RuntimeException
{
    public function __construct(int $slotId)
    {
        parent::__construct("Slot {$slotId} not found.");
    }
}
