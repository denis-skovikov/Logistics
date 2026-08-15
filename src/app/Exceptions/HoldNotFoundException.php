<?php

namespace App\Exceptions;

use RuntimeException;

class HoldNotFoundException extends RuntimeException
{
    public function __construct(int $holdId)
    {
        parent::__construct("Hold {$holdId} not found.");
    }
}
