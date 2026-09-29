<?php

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin settings for one business-initiated message (see `MessageCatalogue`).
 *
 * @property int $id
 * @property string $key
 * @property string $whatsapp_template
 * @property string $language
 * @property string|null $sms_text
 * @property bool $enabled
 * @property int|null $updated_by
 */
class MessageTemplate extends Model
{
    protected $fillable = ['key', 'whatsapp_template', 'language', 'sms_text', 'enabled', 'updated_by'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
