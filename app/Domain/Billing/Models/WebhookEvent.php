<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $event
 * @property string $hash
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
class WebhookEvent extends Model
{
    protected $fillable = ['provider', 'event', 'hash', 'payload', 'processed_at', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
