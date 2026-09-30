<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileNameController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Auth/Name');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => Fields::personName()]);

        $request->user()->update($data);

        return redirect()->intended(route('home'));
    }
}
