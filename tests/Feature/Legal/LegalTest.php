<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Impersonation;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Legal\Models\LegalAcceptance;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LenderFixtures;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class, LenderFixtures::class);

it('publishes the legal pages for the whole platform with the company details filled in', function () {
    Storage::fake('local'); // the sitemap file
    config(['lotlink.legal.company' => 'Test Motors Ltd', 'lotlink.legal.privacy_email' => 'dpo@test.ng']);

    foreach (array_keys(LegalDocuments::TITLES) as $doc) {
        $this->get(route('legal.show', $doc))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Legal/Show')
            ->where('title', LegalDocuments::TITLES[$doc])->where('version', LegalDocuments::version($doc))
            ->where('html', fn (string $html) => str_contains($html, 'Test Motors Ltd') && ! str_contains($html, '{company}') && ! str_contains($html, '<script')));
    }
    $this->get(route('legal.show', 'privacy'))->assertInertia(fn (Assert $page) => $page
        ->where('html', fn (string $html) => str_contains($html, 'Nigeria Data Protection Act 2023') && str_contains($html, 'dpo@test.ng'))
        ->where('sections', fn ($sections) => collect($sections)->pluck('id')->contains('7-your-rights-ndpa-sections-34-to-38')));
    $this->get(route('legal.show', 'terms'))->assertInertia(fn (Assert $page) => $page->where('html', fn (string $html) => str_contains($html, 'Exclusions and limits of our liability')));
    $this->get(route('legal.show', 'lender-terms'))->assertInertia(fn (Assert $page) => $page->where('html', fn (string $html) => str_contains($html, 'Individual lenders')));
    $this->get('/cookies-and-stuff')->assertNotFound();

    $this->get('/.well-known/security.txt')->assertOk()->assertSee('Contact: mailto:')->assertSee('Expires:');
    $this->get('/sitemap.xml')->assertSee(route('legal.show', 'privacy'));
});

it('records acceptance when someone signs up on the sign-in page', function () {
    $this->post(route('login.send'), ['phone' => '0803 555 0101']);
    $this->post(route('login.check'), ['code' => $this->lastCode('+2348035550101')]);

    $user = User::where('phone', '+2348035550101')->sole();
    expect($user->terms_version)->toBe(LegalDocuments::userVersion())
        ->and(LegalAcceptance::where('user_id', $user->id)->pluck('document')->sort()->values()->all())->toBe(['privacy', 'terms']);
});

it('asks people who have not accepted the current terms before they continue, but never during "log in as"', function () {
    $user = User::factory()->withoutTerms()->create();

    $this->actingAs($user)->get(route('bookings.index'))->assertRedirect(route('legal.accept'));
    $this->actingAs($user)->get(route('account'))->assertOk(); // they can still reach their account (and close it)
    $this->actingAs($user)->get(route('legal.accept'))->assertInertia(fn (Assert $page) => $page->component('Legal/Accept')->where('updated', false));
    $this->actingAs($user)->post(route('legal.accept.store'), [])->assertSessionHasErrors('agree');
    $this->actingAs($user)->post(route('legal.accept.store'), ['agree' => true])->assertRedirect();

    expect($user->fresh()->terms_version)->toBe(LegalDocuments::userVersion())
        ->and(LegalAcceptance::where('user_id', $user->id)->count())->toBe(2);
    $this->actingAs($user)->get(route('bookings.index'))->assertOk();

    // A new version asks again.
    config(['lotlink.legal.versions.terms' => '2099-01-01']);
    $this->actingAs($user)->get(route('bookings.index'))->assertRedirect(route('legal.accept'));
    $this->actingAs($user)->get(route('legal.accept'))->assertInertia(fn (Assert $page) => $page->where('updated', true));

    // Admins aren't asked; support can't accept for someone.
    $this->actingAs(User::factory()->admin()->withoutTerms()->create())->get(route('bookings.index'))->assertOk();
    $this->actingAs($user)->withSession([Impersonation::SESSION_KEY => 1])
        ->post(route('legal.accept.store'), ['agree' => true])->assertForbidden();
});

it('makes the app accept the terms too', function () {
    $user = User::factory()->withoutTerms()->create();
    $token = $user->createToken('app')->plainTextToken;
    $api = fn () => $this->withToken($token);

    $this->getJson('/api/v1/legal')->assertOk()->assertJsonPath('required_version', LegalDocuments::userVersion())->assertJsonCount(4, 'data');
    $api()->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.terms.accepted', false);
    $api()->getJson('/api/v1/saved')->assertForbidden()->assertJsonPath('code', 'terms_not_accepted');
    $api()->postJson('/api/v1/legal/accept', ['agree' => true])->assertOk()->assertJsonPath('data.terms.accepted', true);
    $api()->getJson('/api/v1/saved')->assertOk();
});

it('holds a lender\'s applications until one of its admins accepts the Lender Terms', function () {
    $admin = User::factory()->create();
    $officer = User::factory()->create();
    $lender = $this->lender(['terms_version' => null, 'terms_accepted_at' => null], $admin);
    $lender->members()->attach($officer->id, ['role' => 'officer']);

    $this->actingAs($officer)->get(route('lender.applications.index', $lender))->assertRedirect(route('lender.dashboard', $lender));
    $this->actingAs($officer)->get(route('lender.dashboard', $lender))->assertOk()->assertInertia(fn (Assert $page) => $page->where('currentLender.terms_ok', false));
    $this->actingAs($officer)->post(route('lender.terms', $lender), ['agree' => true])->assertForbidden();
    $this->actingAs($admin)->post(route('lender.terms', $lender), ['agree' => true])->assertSessionHasNoErrors();

    expect($lender->fresh()->terms_version)->toBe(LegalDocuments::version('lender-terms'))
        ->and(LegalAcceptance::where(['lender_id' => $lender->id, 'document' => 'lender-terms', 'user_id' => $admin->id])->exists())->toBeTrue();
    $this->actingAs($officer)->get(route('lender.applications.index', $lender))->assertOk();
});

it('hands the buyer over to the lender with its next steps after a yes', function () {
    $officer = User::factory()->create();
    $lender = $this->lender(['name' => 'Ade Loans', 'licence_type' => 'individual', 'next_steps' => 'Call Ade on 0803 000 1111 with your NIN.'], $officer);
    $lot = Lot::factory()->active()->create();
    $car = $this->car($lot, 'Toyota', 'Camry', ['price' => 1_000_000_000]);
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->post(route('finance.store', $car->ulid), [
        'lender' => $lender->slug, 'monthly_income' => '1500000', 'monthly_commitments' => '0', 'employment' => 'salaried',
        'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true,
    ])->assertSessionHasNoErrors();
    $application = FinanceApplication::sole();

    $this->actingAs($buyer)->get(route('finance.show', $application))->assertInertia(fn (Assert $page) => $page->where('application.continue', null));

    $this->actingAs($officer)->post(route('lender.applications.status', [$lender, $application]), ['action' => 'pre_approve', 'approved_amount' => '7000000'])->assertSessionHasNoErrors();
    expect($application->fresh()->next_steps)->toBe('Call Ade on 0803 000 1111 with your NIN.');
    $this->actingAs($officer)->post(route('lender.applications.status', [$lender, $application]), [
        'action' => 'approve', 'approved_amount' => '7000000', 'rate' => '28', 'tenor_months' => 36, 'next_steps' => 'Come to our office at 12 Allen Avenue to sign.',
    ])->assertSessionHasNoErrors();

    $this->actingAs($buyer)->get(route('finance.show', $application))->assertInertia(fn (Assert $page) => $page
        ->where('application.continue.steps', 'Come to our office at 12 Allen Avenue to sign.')
        ->where('application.continue.email', 'loans@kobo.test'));
    expect($buyer->notifications()->latest()->first()->data['text'])->toContain('Continue with Ade Loans');
});

it('clears the details of loan applications closed more than 24 months ago', function () {
    Storage::fake('local');
    $lot = Lot::factory()->active()->create();
    $car = $this->car($lot, 'Toyota', 'Camry', ['price' => 1_000_000_000]);
    $lender = $this->lender(['integration' => LenderIntegration::Demo]);
    $this->actingAs(User::factory()->create())->post(route('finance.store', $car->ulid), [
        'lender' => $lender->slug, 'monthly_income' => '200000', 'monthly_commitments' => '0', 'employment' => 'salaried', 'employer' => 'Dangote',
        'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true,
    ]);
    $old = FinanceApplication::sole();
    expect($old->status)->toBe(FinanceStatus::Declined);
    Storage::disk('local')->put('finance/x/doc.pdf', 'pdf');
    FinanceMessage::create(['finance_application_id' => $old->id, 'side' => 'buyer', 'body' => 'payslip', 'attachment_path' => 'finance/x/doc.pdf']);

    $this->travel(23)->months();
    $this->artisan('finance:prune')->assertSuccessful();
    expect($old->fresh()->applicant)->not->toBe([]);

    $this->travel(2)->months();
    $this->artisan('finance:prune')->assertSuccessful();
    expect($old->fresh())->applicant->toBe([])->status->toBe(FinanceStatus::Declined)->amount->toBe(700_000_000)
        ->and(FinanceMessage::where('finance_application_id', $old->id)->count())->toBe(0);
    Storage::disk('local')->assertMissing('finance/x/doc.pdf');
});
