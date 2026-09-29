<?php

namespace App\Domain\Trust\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use App\Domain\Trust\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * A lot's CAC certificate and frontage photo (TDD M14). The files stay on the private disk and
 * only admins and the lot's owner can open them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property string $cac_number
 * @property list<string> $documents
 * @property string $address_photo_path
 * @property VerificationStatus $status
 * @property int|null $submitted_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
class LotVerification extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const DISK = 'local';

    protected $fillable = ['lot_id', 'cac_number', 'documents', 'address_photo_path', 'status', 'submitted_by', 'reviewed_by', 'reviewed_at', 'notes'];

    protected $hidden = ['id', 'lot_id', 'documents', 'address_photo_path', 'submitted_by', 'reviewed_by'];

    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'documents' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** Short-lived link to a private file: 'certificate' or 'frontage'. */
    public function fileUrl(string $file): string
    {
        return URL::temporarySignedRoute('verifications.file', now()->addMinutes(30), ['verification' => $this->ulid, 'file' => $file]);
    }

    public function filePath(string $file): ?string
    {
        return match ($file) {
            'certificate' => $this->documents[0] ?? null,
            'frontage' => $this->address_photo_path,
            default => null,
        };
    }

    /** @return array<string, mixed> what the owner sees in onboarding and Settings */
    public function toDealerArray(string $timezone): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'cac_number' => $this->cacLabel(),
            'notes' => $this->notes,
            'submitted_at' => $this->created_at?->timezone($timezone)->format('j M Y'),
            'certificate_url' => $this->fileUrl('certificate'),
            'frontage_url' => $this->fileUrl('frontage'),
        ];
    }

    /** "RC 1234567" */
    public function cacLabel(): string
    {
        return (preg_match('/^\d/', $this->cac_number) ? 'RC ' : '').$this->cac_number;
    }
}
