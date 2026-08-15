<?php

namespace App\Enums;

enum ResponseHeader: string
{
    case Cache = 'X-Cache';
    case QueryTime = 'X-Query-Time';
    case Provider = 'X-Provider';
}
