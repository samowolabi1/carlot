<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Trust\Actions\DecideLotVerification;
use App\Domain\Trust\Models\LotVerification;
use App\Filament\Resources\LotVerificationResource\Pages\ListLotVerifications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001', 'email' => 'owner@example.com']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->admin = User::factory()->admin()->create();
    $this->send = fn (?User $as = null, array $data = []) => $this->actingAs($as ?? $this->owner)->post(route('dealer.verification.store', $this->lot), [
        'cac_number' => 'RC 1234567',
        'certificate' => UploadedFile::fake()->create('cac.pdf', 300, 'application/pdf'),
        'frontage' => UploadedFile::fake()->image('gate.jpg', 1200, 800),
        ...$data,
    ]);
});

it('lets the owner send CAC documents, kept on the private disk', function () {
    ($this->send)()->assertSessionHasNoErrors()->assertSessionHas('success');

    $v = LotVerification::withoutGlobalScopes()->sole();
    expect($v)->cac_number->toBe('1234567')->status->value->toBe('submitted')
        ->and($v->cacLabel())->toBe('RC 1234567');
    Storage::disk('local')->assertExists([$v->documents[0], $v->address_photo_path]);

    $this->actingAs($this->owner)->get(route('dealer.settings', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('verification.current.status', 'submitted')->where('verification.verified', false));

    // Sending again while waiting replaces the files rather than queueing twice.
    $old = $v->address_photo_path;
    ($this->send)();
    expect(LotVerification::withoutGlobalScopes()->count())->toBe(1);
    Storage::disk('local')->assertMissing($old);
});

it('checks the CAC number and files, and only the owner may send them', function () {
    ($this->send)(null, ['cac_number' => 'hello'])->assertSessionHasErrors('cac_number');
    ($this->send)(null, ['frontage' => UploadedFile::fake()->create('gate.pdf', 10, 'application/pdf')])->assertSessionHasErrors('frontage');

    $manager = User::factory()->staff()->create();
    $this->lot->members()->attach($manager, ['role' => LotRole::Manager->value, 'accepted_at' => now()]);
    ($this->send)($manager)->assertForbidden();

    // Another lot's owner can't reach this lot at all.
    $other = User::factory()->staff()->create();
    app(CreateLot::class)->run($other, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    ($this->send)($other)->assertForbidden();
    expect(LotVerification::withoutGlobalScopes()->count())->toBe(0);
});

it('serves the documents only through short-lived signed links', function () {
    ($this->send)();
    $v = LotVerification::withoutGlobalScopes()->sole();

    $this->get($v->fileUrl('certificate'))->assertOk();
    $this->get(route('verifications.file', [$v->ulid, 'certificate']))->assertForbidden();
    $this->travel(31)->minutes();
    $this->get($v->fileUrl('frontage'))->assertOk(); // a fresh link
});

it('gives the lot its badge when an admin approves, and a note when rejected', function () {
    ($this->send)();
    $v = LotVerification::withoutGlobalScopes()->sole();

    expect(fn () => app(DecideLotVerification::class)->reject($v, $this->admin, ' '))->toThrow(ValidationException::class);
    app(DecideLotVerification::class)->reject($v, $this->admin, 'The photo is too dark to see your sign.');
    expect($this->lot->fresh()->verified_at)->toBeNull()
        ->and($this->owner->notifications()->where('data->kind', 'verification')->sole()->data['text'])->toContain('too dark');

    ($this->send)();
    $v = LotVerification::withoutGlobalScopes()->latest('id')->first();
    app(DecideLotVerification::class)->approve($v, $this->admin);

    expect($this->lot->fresh()->verified_at)->not->toBeNull();
    $this->get(route('lots.show', $this->lot))->assertInertia(fn (Assert $page) => $page->where('lot.verified', true));
    ($this->send)()->assertSessionHasErrors('cac_number');
});

it('lets admins approve from the review queue', function () {
    ($this->send)();
    $v = LotVerification::withoutGlobalScopes()->sole();

    $this->actingAs($this->admin);
    $this->get('/admin/lot-verifications')->assertOk();
    Livewire::test(ListLotVerifications::class)
        ->assertCanSeeTableRecords([$v])
        ->callTableAction('approve', $v, ['notes' => 'Checked on the CAC portal']);

    expect($v->fresh()->status->value)->toBe('approved')->and($this->lot->fresh()->isVerified())->toBeTrue();
});
