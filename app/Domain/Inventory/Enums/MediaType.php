<?php

namespace App\Domain\Inventory\Enums;

/** Video and 360° spins are phase 2 (product spec). */
enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Spin360 = 'spin360';
}
