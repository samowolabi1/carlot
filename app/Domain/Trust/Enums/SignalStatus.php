<?php

namespace App\Domain\Trust\Enums;

enum SignalStatus: string
{
    case Open = 'open';
    case Cleared = 'cleared';
    case Actioned = 'actioned';
}
