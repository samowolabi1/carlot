<?php

namespace App\Domain\Trust\Enums;

enum ReviewStatus: string
{
    case Visible = 'visible';
    case Hidden = 'hidden';
}
