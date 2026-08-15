<?php

namespace App\Enums;

enum CacheStatus: string
{
    case Hit = 'HIT';
    case Miss = 'MISS';
    case CacheQueryTime = 'Cache';
}
