<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Trust\Models\Inspection;
use App\Domain\Trust\Support\InspectionChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    Storage::fake('local');
    $this->media = Storage::fake(config('lotlink.media_disk'));
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_250_000_000]);

    // Everything passes, except what a test changes.
    $this->checklist = fn (array $changes = []) => array_replace(
        collect(InspectionChecklist::keys())->mapWithKeys(fn ($k) => [$k => ['status' => 'pass', 'note' => null]])->all(),
        $changes,
    );
    $this->inspect = fn (array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->owner)
        ->post(route('dealer.vehicles.inspection.store', [$this->lot, $this->car]), ['checklist' => ($this->checklist)(), ...$data]);
});

it('has 40 checks in seven groups and scores an advisory as half', function () {
    expect(InspectionChecklist::keys())->toHaveCount(40)->and(InspectionChecklist::GROUPS)->toHaveCount(7)
        ->and(InspectionChecklist::score(($this->checklist)()))->toBe(100)
        ->and(InspectionChecklist::score(($this->checklist)(['paint' => ['status' => 'advisory'], 'tread_front' => ['status' => 'fail']])))->toBe(96);
});

it('saves the lot\'s inspection and shows it to buyers with a PDF', function () {
    ($this->inspect)([
        'checklist' => ($this->checklist)([
            'paint' => ['status' => 'advisory', 'note' => 'Stone chips on the bonnet'],
            'tread_front' => ['status' => 'fail', 'note' => 'Front tyres at 2 mm'],
        ]),
        'summary' => 'Serviced last month.',
        'inspector_name' => 'Kemi (workshop)',
        'photos' => ['tread_front' => [UploadedFile::fake()->image('tyre.jpg', 1600, 1200)]],
    ])->assertRedirect(route('dealer.vehicles.index', $this->lot))->assertSessionHas('success');

    $inspection = Inspection::withoutGlobalScopes()->sole();
    expect($inspection)->score->toBe(96)->inspector_name->toBe('Kemi (workshop)')->signed_at->toBeNull()
        ->and($this->car->fresh()->inspection_id)->toBe($inspection->id);
    $this->media->assertExists($inspection->photos['tread_front'][0]);

    $this->get($this->car->publicPath())->assertInertia(fn (Assert $page) => $page
        ->where('car.inspection.score', 96)
        ->where('car.inspection.independent', false)
        ->where('car.inspection.groups.4.result', 'fail')
        ->where('car.inspection.groups.4.issues.0.note', 'Front tyres at 2 mm')
        ->has('car.inspection.photos', 1));

    $this->get(route('inspections.pdf', $inspection))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($inspection->fresh()->report_path)->not->toBeNull();
    $this->get(route('cars.index'))->assertInertia(fn (Assert $page) => $page->where('results.data.0.inspected', true));
});

it('needs a note for every fail and an answer for every check', function () {
    ($this->inspect)(['checklist' => ($this->checklist)(['brakes' => ['status' => 'fail', 'note' => '']])])->assertSessionHasErrors('checklist.brakes.note');

    $partial = ($this->checklist)();
    unset($partial['airbags']);
    ($this->inspect)(['checklist' => $partial])->assertSessionHasErrors('checklist.airbags.status');
    expect(Inspection::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps inspections to the lot\'s own team, and the PDF private once the car is off sale', function () {
    ($this->inspect)();
    $inspection = Inspection::withoutGlobalScopes()->sole();

    $stranger = User::factory()->staff()->create();
    app(CreateLot::class)->run($stranger, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $this->actingAs($stranger)->get(route('dealer.vehicles.inspection', [$this->lot, $this->car]))->assertForbidden();
    ($this->inspect)([], $stranger)->assertForbidden();

    $this->car->forceFill(['status' => 'hidden'])->save();
    auth()->logout();
    $this->get(route('inspections.pdf', $inspection))->assertNotFound();
    $this->actingAs($this->owner)->get(route('inspections.pdf', $inspection))->assertOk();
});

it('lets a registered inspector sign an independent report that the lot cannot bury', function () {
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->get(route('inspector.create', $this->car->ulid))->assertForbidden();

    $inspector = User::factory()->create(['name' => 'Tayo Bello']);
    $inspector->forceFill(['inspector_since' => now(), 'inspector_company' => 'AutoCheck NG'])->save();

    $this->actingAs($inspector)->get(route('inspector.create', $this->car->ulid))->assertInertia(fn (Assert $page) => $page->component('Trust/Inspect')->where('inspector', 'Tayo Bello, AutoCheck NG'));
    $this->actingAs($inspector)->post(route('inspector.store', $this->car->ulid), ['checklist' => ($this->checklist)(['ac' => ['status' => 'advisory']])])
        ->assertRedirect($this->car->publicPath());

    $signed = Inspection::withoutGlobalScopes()->sole();
    expect($signed->isIndependent())->toBeTrue()->and($signed->inspector_name)->toBe('Tayo Bello, AutoCheck NG');

    // The lot's own later check is kept, but the car still shows the independent report.
    ($this->inspect)();
    expect(Inspection::withoutGlobalScopes()->count())->toBe(2)->and($this->car->fresh()->inspection_id)->toBe($signed->id);
    $this->get($this->car->publicPath())->assertInertia(fn (Assert $page) => $page->where('car.inspection.independent', true)->where('car.inspection.label', 'Independently inspected'));
});
