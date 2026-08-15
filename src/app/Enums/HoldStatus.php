<?php

namespace App\Enums;

enum HoldStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
