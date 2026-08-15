<?php

namespace App\Exceptions;

use RuntimeException;

class HoldConflictException extends RuntimeException
{
    public function __construct(string $currentStatus)
    {
        parent::__construct("Hold cannot be modified. Current status: {$currentStatus}.");
    }
}
