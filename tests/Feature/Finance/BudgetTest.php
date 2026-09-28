<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Models\Budget;
use App\Domain\Lots\Models\Lot;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

$design = ['monthly_income' => 1_300_000, 'monthly_commitments' => 330_000, 'deposit' => 3_750_000, 'tenor_months' => 36, 'interest_rate' => 24];

it('lets anyone use the calculator', function () {
    $this->get(route('budget'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Marketplace/Budget')
            ->where('saved', null)
            ->where('finance.affordability_ratio', 0.35)
            ->where('finance.tenors', [12, 24, 36, 48]));
});

it('saves a budget, working out the maximum price on the server', function () use ($design) {
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->put(route('budget.update'), [...$design, 'max_price' => 999_999_999])->assertSessionHasNoErrors();

    $budget = Budget::sole();
    expect($budget->user_id)->toBe($buyer->id)
        ->and($budget->max_price)->toBe(1_240_000_000) // ₦12.4m in kobo, not the value sent
        ->and($budget->monthly_income)->toBe(130_000_000);

    $this->actingAs($buyer)->get(route('budget'))->assertInertia(fn (Assert $page) => $page->where('saved.max_price', 12_400_000));
    // Every page knows the budget, for "Within budget" tags.
    $this->actingAs($buyer)->get('/')->assertInertia(fn (Assert $page) => $page->where('budget', 12_400_000));
});

it('updates the same budget rather than adding another, and can remove it', function () use ($design) {
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->put(route('budget.update'), $design);
    $this->actingAs($buyer)->put(route('budget.update'), [...$design, 'deposit' => 5_000_000]);
    expect(Budget::count())->toBe(1)->and(Budget::sole()->max_price)->toBe(1_365_000_000);

    $this->actingAs($buyer)->delete(route('budget.destroy'))->assertRedirect();
    expect(Budget::count())->toBe(0);
});

it('needs an account to save and valid numbers', function () use ($design) {
    $this->put(route('budget.update'), $design)->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->put(route('budget.update'), [...$design, 'tenor_months' => 7, 'monthly_income' => 0])
        ->assertSessionHasErrors(['tenor_months', 'monthly_income']);
});

it('counts the live cars a budget covers', function () {
    $lot = Lot::factory()->active()->create();
    $this->car($lot, 'Toyota', 'Camry', ['price' => 1_200_000_000]);
    $this->car($lot, 'Lexus', 'RX', ['price' => 3_450_000_000]);
    $this->car(Lot::factory()->create(), 'Honda', 'Accord', ['price' => 500_000_000]); // lot not approved

    $this->getJson(route('budget.count', ['max' => 12_400_000]))->assertExactJson(['count' => 1]);
    $this->getJson(route('budget.count', ['max' => 40_000_000]))->assertExactJson(['count' => 2]);
});

it('shows monthly cost and running costs on the car page', function () {
    $lot = Lot::factory()->active()->create();
    $car = $this->car($lot, 'Toyota', 'Camry', ['price' => 1_250_000_000, 'engine_cc' => 2500, 'year' => 2018]);

    $this->get($car->publicPath())->assertInertia(fn (Assert $page) => $page
        ->where('finance.price', 12_500_000)
        ->where('finance.from.monthly', 343_500)
        ->has('finance.ownership.items', 4));
});

it('keeps each buyer\'s budget to themselves', function () use ($design) {
    $ada = User::factory()->create();
    $bola = User::factory()->create();
    $this->actingAs($ada)->put(route('budget.update'), $design);

    $this->actingAs($bola)->get(route('budget'))->assertInertia(fn (Assert $page) => $page->where('saved', null)->where('budget', null));
    $this->actingAs($bola)->delete(route('budget.destroy'));

    expect(Budget::where('user_id', $ada->id)->exists())->toBeTrue();
});
