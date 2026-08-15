<?php

namespace App\Exceptions;

use RuntimeException;

class CapacityExhaustedException extends RuntimeException
{
    public function __construct(int $slotId)
    {
        parent::__construct("No available capacity for slot {$slotId}.");
    }
}
