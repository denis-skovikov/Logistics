<?php

namespace App\Exceptions;

use RuntimeException;

class HoldExpiredException extends RuntimeException
{
    public function __construct(int $holdId)
    {
        parent::__construct("Hold {$holdId} expired and has been cancelled.");
    }
}
