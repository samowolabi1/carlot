<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the home page', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Home')->where('lotCount', 0));
});

it('renders the sign-in page', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});
