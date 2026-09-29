<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\Actions\LogInWithPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Email (or WhatsApp number) and password, for accounts that added one. */
class PasswordLoginController extends Controller
{
    public function store(Request $request, LogInWithPassword $logIn): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['boolean'],
        ]);

        $user = $logIn->attempt($data['login'], $data['password'], $request->ip());
        Auth::login($user, remember: $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
