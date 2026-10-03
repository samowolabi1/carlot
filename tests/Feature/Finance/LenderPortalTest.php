<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Finance\Actions\DecideLender;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Notifications\FinanceUpdate;
use App\Domain\Finance\Notifications\LenderAlert;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Actions\CreateLot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LenderFixtures;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class, LenderFixtures::class);

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233', 'state' => 'Lagos']);
    $this->lot->update(['status' => 'active']);
    $this->car = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_000_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777', 'email' => 'tunde@example.com']);

    $this->officer = User::factory()->create(['name' => 'Kemi Adeyemi', 'email' => 'kemi@kobo.test']);
    $this->kobo = $this->lender(['name' => 'Kobo Motor Finance'], $this->officer);

    $this->apply = fn (?Lender $lender = null) => $this->actingAs($this->buyer)->post(route('finance.store', $this->car->ulid), [
        'lender' => ($lender ?? $this->kobo)->slug, 'monthly_income' => '₦1,500,000', 'monthly_commitments' => '100000', 'employment' => 'salaried',
        'employer' => 'Dangote', 'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true,
    ])->assertSessionHasNoErrors();
    $this->act = fn (FinanceApplication $a, array $data, ?User $as = null) => $this->actingAs($as ?? $this->officer)
        ->post(route('lender.applications.status', [$this->kobo, $a]), $data);
});

it('lets a lender sign up, waits for an admin, then offers it to buyers', function () {
    $applicant = User::factory()->create(['name' => 'Bola Ade', 'email' => 'bola@sterling.test']);
    $form = [
        'name' => 'Sterling Auto Loans', 'licence_type' => 'commercial_bank', 'licence_number' => 'CBN/2019/0042', 'contact_name' => 'Bola Ade',
        'contact_email' => 'bola@sterling.test', 'contact_phone' => '0803 555 1234', 'rate' => '22.5', 'min_amount' => '₦2,000,000', 'max_amount' => '40,000,000',
        'min_deposit_percent' => 20, 'tenors' => [12, 24, 36], 'states' => ['Lagos', 'FCT'], 'agree' => true,
        'licence' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
    ];

    $this->post(route('lenders.store'), $form)->assertRedirect(route('login'));
    $this->actingAs($applicant)->post(route('lenders.store'), [...$form, 'licence' => null])->assertSessionHasErrors('licence');
    $this->actingAs($applicant)->post(route('lenders.store'), [...$form, 'min_amount' => '1500.50'])->assertSessionHasErrors('min_amount');
    $this->actingAs($applicant)->post(route('lenders.store'), $form)->assertSessionHasNoErrors();

    $lender = Lender::where('name', 'Sterling Auto Loans')->sole();
    expect($lender)->status->toBe(LenderStatus::Pending)->rate_bp->toBe(2250)->min_amount->toBe(200_000_000)->states->toBe(['FCT', 'Lagos'])
        ->contact_phone->toBe('+2348035551234')->and($lender->roleOf($applicant))->toBe(LenderRole::Admin)
        ->and(AdminCounters::all()['lenders_waiting'])->toBe(1);
    Storage::disk('local')->assertExists($lender->licence_path);

    $this->actingAs($applicant)->get(route('lender.home'))->assertRedirect(route('lender.dashboard', $lender));
    $this->actingAs($applicant)->get(route('lender.dashboard', $lender))->assertInertia(fn (Assert $page) => $page->component('Lender/Dashboard')
        ->where('currentLender.status', 'pending'));
    $this->actingAs($this->buyer)->get(route('finance.create', $this->car->ulid))->assertInertia(fn (Assert $page) => $page->has('lenders', 1));

    Notification::fake();
    app(DecideLender::class)->run($lender, 'approve', User::factory()->admin()->create());

    Notification::assertSentTo($applicant, LenderAlert::class, fn (LenderAlert $n) => str_contains($n->text, 'is approved'));
    $this->actingAs($this->buyer)->get(route('finance.create', $this->car->ulid))->assertInertia(fn (Assert $page) => $page->has('lenders', 2));
    expect(fn () => app(DecideLender::class)->run($lender, 'suspend', User::factory()->admin()->create()))->toThrow(ValidationException::class);
});

it('keeps each lender to its own portal and applications', function () {
    ($this->apply)();
    $application = FinanceApplication::sole();
    $rivalOfficer = User::factory()->create();
    $rival = $this->lender(['name' => 'Rival Finance'], $rivalOfficer);

    $this->actingAs($rivalOfficer)->get(route('lender.dashboard', $this->kobo))->assertForbidden();
    $this->actingAs($rivalOfficer)->get(route('lender.applications.show', [$rival, $application]))->assertNotFound();
    $this->actingAs($rivalOfficer)->post(route('lender.applications.status', [$rival, $application]), ['action' => 'review'])->assertNotFound();
    $this->actingAs($this->buyer)->get(route('lender.applications.index', $this->kobo))->assertForbidden();
    $this->actingAs($this->owner)->get(route('lender.applications.show', [$this->kobo, $application]))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('lender.dashboard', $this->kobo))->assertForbidden();

    $this->actingAs($rivalOfficer)->get(route('lender.applications.index', $rival))->assertInertia(fn (Assert $page) => $page->has('applications.data', 0));
    $this->actingAs($this->officer)->get(route('lender.applications.index', $this->kobo))->assertInertia(fn (Assert $page) => $page->has('applications.data', 1)
        ->where('applications.data.0.buyer', 'Tunde Adebayo'));
    $this->actingAs($this->officer)->get(route('lender.applications.index', ['lender' => $this->kobo, 'q' => 'Honda']))
        ->assertInertia(fn (Assert $page) => $page->has('applications.data', 0));
});

it('works an application from review to payment, telling the buyer each step and the seller only the good news', function () {
    ($this->apply)();
    $application = FinanceApplication::sole();
    Notification::fake();

    // The lender sees what the buyer shared; the buyer's own page never carries it.
    $this->actingAs($this->officer)->get(route('lender.applications.show', [$this->kobo, $application]))->assertInertia(fn (Assert $page) => $page
        ->where('application.applicant.monthly_income', '₦1,500,000')->where('application.applicant.employer', 'Dangote')->where('actions', ['review', 'documents', 'pre_approve', 'approve', 'decline']));
    $this->actingAs($this->buyer)->get(route('finance.show', $application))->assertInertia(fn (Assert $page) => $page->where('application.applicant', null));

    ($this->act)($application, ['action' => 'disburse', 'disbursed_amount' => '7000000', 'disbursed_reference' => 'TRF-1'])->assertSessionHasErrors('status');
    ($this->act)($application, ['action' => 'review'])->assertSessionHasNoErrors();
    ($this->act)($application, ['action' => 'documents'])->assertSessionHasErrors('message');
    ($this->act)($application, ['action' => 'documents', 'message' => '3 months of bank statements'])->assertSessionHasNoErrors();
    expect($application->fresh())->status->toBe(FinanceStatus::DocumentsRequested)->assigned_to->toBe($this->officer->id);
    Notification::assertSentTo($this->buyer, FinanceUpdate::class, fn (FinanceUpdate $n) => str_contains($n->text, 'needs some documents'));

    // The buyer uploads; the lender's officer is told and can open it; nobody else can.
    $this->actingAs($this->buyer)->post(route('finance.message', $application), ['body' => 'Statements attached', 'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf')])
        ->assertSessionHasNoErrors();
    Notification::assertSentTo($this->officer, LenderAlert::class, fn (LenderAlert $n) => str_contains($n->text, 'sent a message and a document'));
    $file = URL::temporarySignedRoute('finance.file', now()->addMinutes(5), ['message' => FinanceMessage::whereNotNull('attachment_path')->sole()->ulid]);
    $this->actingAs($this->officer)->get($file)->assertOk();
    $this->actingAs($this->buyer)->get($file)->assertOk();
    $this->actingAs($this->owner)->get($file)->assertNotFound();
    $this->actingAs($this->officer)->get(route('finance.file', ['message' => FinanceMessage::whereNotNull('attachment_path')->sole()->ulid]))->assertForbidden(); // unsigned

    ($this->act)($application, ['action' => 'pre_approve', 'approved_amount' => '6,500,000'])->assertSessionHasNoErrors();
    expect($application->fresh()->partner_message)->toBeNull(); // the "send documents" note doesn't linger
    ($this->act)($application, ['action' => 'approve', 'approved_amount' => '8000000', 'rate' => '23', 'tenor_months' => 36])->assertSessionHasErrors('approved_amount');
    ($this->act)($application, ['action' => 'approve', 'approved_amount' => '6500000', 'rate' => '23', 'tenor_months' => 36])->assertSessionHasNoErrors();
    ($this->act)($application, ['action' => 'disburse', 'disbursed_amount' => '6500000', 'disbursed_reference' => 'TRF/2027/88'])->assertSessionHasNoErrors();

    expect($application->fresh())->status->toBe(FinanceStatus::Disbursed)->approved_amount->toBe(650_000_000)->offer_rate_bp->toBe(2300)
        ->disbursed_amount->toBe(650_000_000)->disbursed_reference->toBe('TRF/2027/88');
    Notification::assertSentTo($this->buyer, FinanceUpdate::class, fn (FinanceUpdate $n) => str_contains($n->text, 'approved a loan of ₦6,500,000 for the 2018 Toyota Camry at 23% a year over 36 months'));
    Notification::assertSentTo($this->owner, DealAlert::class, fn (DealAlert $n) => str_contains($n->text, 'paid ₦6,500,000 to your business'));
    $lotLines = Message::query()->where('side', Message::SYSTEM)->pluck('body')->implode("\n");
    expect($lotLines)->toContain('approved a car loan of ₦6,500,000')->not->toContain('1,500,000')->not->toContain('statements')->not->toContain('Dangote');
});

it('never tells the seller about a decline, and lets the buyer withdraw', function () {
    ($this->apply)();
    $first = FinanceApplication::sole();
    Notification::fake();

    ($this->act)($first, ['action' => 'decline', 'message' => 'Income too low for this amount.'])->assertSessionHasNoErrors();
    Notification::assertSentTo($this->buyer, FinanceUpdate::class);
    Notification::assertNotSentTo($this->owner, DealAlert::class);
    expect(Message::query()->where('body', 'like', '%declin%')->orWhere('body', 'like', '%couldn%')->exists())->toBeFalse();

    ($this->apply)();
    $second = FinanceApplication::latest('id')->first();
    $this->actingAs($this->buyer)->post(route('finance.withdraw', $second))->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe(FinanceStatus::Withdrawn);
    Notification::assertSentTo($this->officer, LenderAlert::class, fn (LenderAlert $n) => str_contains($n->text, 'withdrew'));
    ($this->act)($second, ['action' => 'review'])->assertSessionHasErrors('status');
    $this->actingAs(User::factory()->create())->post(route('finance.withdraw', $first))->assertNotFound();
});

it('lets a suspended lender see its applications but not move them', function () {
    ($this->apply)();
    $application = FinanceApplication::sole();
    $this->kobo->update(['status' => LenderStatus::Suspended]);

    $this->actingAs($this->officer)->get(route('lender.applications.show', [$this->kobo, $application]))->assertInertia(fn (Assert $page) => $page->where('actions', []));
    ($this->act)($application, ['action' => 'review'])->assertForbidden();
});

it('lets lender admins manage the team and settings, not officers', function () {
    $this->actingAs($this->officer)->post(route('lender.team.store', $this->kobo), ['name' => 'Ngozi Obi', 'email' => 'ngozi@kobo.test', 'role' => 'officer'])
        ->assertSessionHasNoErrors();
    $ngozi = User::where('email', 'ngozi@kobo.test')->sole();
    expect($this->kobo->roleOf($ngozi))->toBe(LenderRole::Officer);
    $this->actingAs($ngozi)->get(route('lender.dashboard', $this->kobo))->assertRedirect(route('legal.accept'));
    $this->actingAs($ngozi)->post(route('legal.accept.store'), ['agree' => true]);

    $this->actingAs($ngozi)->post(route('lender.team.store', $this->kobo), ['name' => 'Eve', 'email' => 'eve@x.test', 'role' => 'admin'])->assertForbidden();
    $this->actingAs($ngozi)->put(route('lender.settings.update', $this->kobo), [])->assertForbidden();
    $this->actingAs($this->officer)->delete(route('lender.team.destroy', [$this->kobo, $this->officer->ulid]))->assertSessionHasErrors('member'); // last admin
    $this->actingAs($this->officer)->delete(route('lender.team.destroy', [$this->kobo, $ngozi->ulid]))->assertSessionHasNoErrors();
    expect($this->kobo->roleOf($ngozi))->toBeNull();

    $settings = [
        'contact_name' => 'Kemi Adeyemi', 'contact_email' => 'loans@kobo.test', 'contact_phone' => '08031112222', 'website' => '', 'about' => 'Car loans.',
        'rate' => 25, 'min_amount' => '1000000', 'max_amount' => '50000000', 'min_deposit_percent' => 15, 'tenors' => [12, 24], 'states' => [],
        'integration' => 'api', 'api_url' => 'https://api.kobo.test', 'api_key' => 'secret-key',
    ];
    $this->actingAs($this->officer)->put(route('lender.settings.update', $this->kobo), [...$settings, 'api_url' => ''])->assertSessionHasErrors('api_url');
    $this->actingAs($this->officer)->put(route('lender.settings.update', $this->kobo), $settings)->assertSessionHasNoErrors()->assertSessionHas('lender_secret');
    expect($this->kobo->fresh())->integration->toBe(LenderIntegration::Api)->api_key->toBe('secret-key')->webhook_secret->not->toBeNull()
        ->rate_bp->toBe(2500)->states->toBeNull()
        ->and(Lender::query()->toBase()->where('id', $this->kobo->id)->value('api_key'))->not->toContain('secret-key'); // encrypted
    $this->actingAs($this->officer)->get(route('lender.settings', $this->kobo))->assertInertia(fn (Assert $page) => $page->where('has_key', true)->missing('values.api_key'));
});
