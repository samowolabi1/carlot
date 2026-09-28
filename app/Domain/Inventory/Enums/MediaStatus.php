<?php

namespace App\Domain\Inventory\Enums;

enum MediaStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
