<?php

use App\Domain\Accounts\Models\User;
use Database\Seeders\DemoLenderSeeder;

it('creates a demo lender whose officer can sign in with a password and reach the portal', function () {
    $this->seed(DemoLenderSeeder::class);
    $this->seed(DemoLenderSeeder::class); // safe to run twice

    $this->post(route('login.password'), ['login' => 'lender@lotlink.test', 'password' => 'password'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs(User::where('email', 'lender@lotlink.test')->sole());

    // First visit: accept the Terms and Privacy Policy, then the Lender Terms for Kobo, then the applications open.
    $this->get(route('lender.home'))->assertRedirect(route('legal.accept'));
    $this->post(route('legal.accept.store'), ['agree' => true]);
    $this->get(route('lender.home'))->assertRedirect(route('lender.dashboard', 'kobo-motor-finance'));
    $this->post(route('lender.terms', 'kobo-motor-finance'), ['agree' => true])->assertSessionHasNoErrors();
    $this->get(route('lender.applications.index', 'kobo-motor-finance'))->assertOk();
});
