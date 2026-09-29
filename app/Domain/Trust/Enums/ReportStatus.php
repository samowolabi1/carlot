<?php

namespace App\Domain\Trust\Enums;

enum ReportStatus: string
{
    case Open = 'open';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
