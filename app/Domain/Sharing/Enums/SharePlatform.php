<?php

namespace App\Domain\Sharing\Enums;

/** Where a share link was sent, so views can be attributed (TDD M9). */
enum SharePlatform: string
{
    case WhatsApp = 'whatsapp';
    case Facebook = 'facebook';
    case X = 'x';
    case Telegram = 'telegram';
    case Instagram = 'instagram';
    case Sms = 'sms';
    case Native = 'native'; // the phone's share sheet: we can't tell which app
    case Copy = 'copy';
    case Qr = 'qr';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Facebook => 'Facebook',
            self::X => 'X',
            self::Telegram => 'Telegram',
            self::Instagram => 'Instagram',
            self::Sms => 'SMS',
            self::Native => 'Share sheet',
            self::Copy => 'Copied link',
            self::Qr => 'QR code',
        };
    }
}
