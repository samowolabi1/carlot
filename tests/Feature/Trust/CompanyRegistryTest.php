<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Trust\Models\LotVerification;
use App\Domain\Trust\Registry\DojahCompanyRegistry;
use App\Domain\Trust\Registry\NameMatch;
use App\Domain\Trust\Registry\RegistryUnavailable;
use App\Filament\Resources\LotVerificationResource\Pages\ListLotVerifications;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/* Automatic CAC lookups (TDD M14): the registry's answer is shown to the admin; it never decides by itself. */

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake(); // the driver's retry pauses
    // One faked Dojah whose answer a test can switch (Http::fake stubs don't replace each other).
    $this->answer = null;
    Http::fake(['sandbox.dojah.io/*' => fn () => $this->answer]);
    config(['lotlink.company_registry' => ['driver' => 'dojah', 'dojah' => ['base_url' => 'https://sandbox.dojah.io', 'app_id' => 'app-1', 'secret_key' => 'sk-1']]]);
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'AutoHub Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->send = fn (string $cac = 'RC 1234567') => $this->actingAs($this->owner)->post(route('dealer.verification.store', $this->lot), [
        'cac_number' => $cac,
        'certificate' => UploadedFile::fake()->create('cac.pdf', 300, 'application/pdf'),
        'frontage' => UploadedFile::fake()->image('gate.jpg', 1200, 800),
    ])->assertSessionHasNoErrors();
    $this->found = fn (array $entity = []) => Http::response(['entity' => [
        'company_name' => 'AUTOHUB MOTORS NIGERIA LIMITED', 'rc_number' => '1234567', 'type_of_company' => 'COMPANY',
        'status' => 'ACTIVE', 'date_of_registration' => '2019-03-14', 'address' => '12 Allen Avenue, Ikeja, Lagos', ...$entity,
    ]]);
});

it('looks the number up after the seller sends it and shows the admin what the registry says', function () {
    $this->answer = ($this->found)();

    ($this->send)();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox.dojah.io/api/v1/kyc/cac/basic?rc_number=1234567&company_type=COMPANY'
        && $r->hasHeader('AppId', 'app-1') && $r->hasHeader('Authorization', 'sk-1'));
    $v = LotVerification::withoutGlobalScopes()->sole();
    expect($v)->registry_result->toBe('found')
        ->registry_name->toBe('AUTOHUB MOTORS NIGERIA LIMITED')
        ->registry_status->toBe('Active')
        ->registry_name_match->toBe(100)
        ->status->value->toBe('submitted') // still the admin's decision
        ->and($v->registry_registered_on->toDateString())->toBe('2019-03-14')
        ->and($v->registrySummary())->toBe('AUTOHUB MOTORS NIGERIA LIMITED · Active · registered 14 Mar 2019 · name match 100%')
        ->and($v->registryConcern())->toBeFalse();
});

it('flags numbers the registry does not know, inactive companies and names that do not match', function () {
    $this->answer = Http::response(['error' => 'Not found'], 404);
    ($this->send)();
    $v = LotVerification::withoutGlobalScopes()->sole();
    expect($v->registry_result)->toBe('not_found')->and($v->registryConcern())->toBeTrue();

    $this->answer = ($this->found)(['company_name' => 'SUNRISE FOODS LIMITED', 'status' => 'INACTIVE']);
    ($this->send)('BN 2345678');
    $v->refresh();
    expect($v->registry_status)->toBe('Inactive')
        ->and($v->registry_name_match)->toBeLessThan(60)
        ->and($v->registryConcern())->toBeTrue();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rc_number=2345678&company_type=BUSINESS_NAME'));
});

it('tries again later when the registry is down, and lets an admin re-check', function () {
    $this->answer = Http::response('Server error', 503);

    try {
        ($this->send)();
    } catch (RegistryUnavailable) {
        // the queued job fails and is retried (sync queue in tests surfaces it)
    }
    $v = LotVerification::withoutGlobalScopes()->sole();
    expect($v->registry_result)->toBe('unavailable');

    $this->answer = ($this->found)();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ListLotVerifications::class)->callTableAction('registry', $v);

    expect($v->fresh()->registry_result)->toBe('found');
});

it('asks nothing when no registry is set up', function () {
    config(['lotlink.company_registry.driver' => 'none']);

    ($this->send)();

    Http::assertNothingSent();
    expect(LotVerification::withoutGlobalScopes()->sole()->registrySummary())->toBeNull();
});

it('matches trading names to registered names, ignoring words every company has', function () {
    expect(NameMatch::score('AutoHub', 'AUTOHUB MOTORS NIGERIA LIMITED'))->toBe(100)
        ->and(NameMatch::score('Prime Motors & Sons', 'PRIME MOTORS AND SONS ENTERPRISES'))->toBe(100)
        ->and(NameMatch::score('Prime Motors', 'PRIME AUTOS LTD'))->toBeGreaterThanOrEqual(50)->toBeLessThan(100)
        ->and(NameMatch::score('AutoHub', 'SUNRISE FOODS LIMITED'))->toBeLessThan(40)
        ->and(DojahCompanyRegistry::split('IT55555'))->toBe(['55555', 'INCORPORATED_TRUSTEES']);
});
