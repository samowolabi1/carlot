<?php

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Actions\DeleteAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Account → Delete my account (TDD Privacy). */
class AccountDeletionController extends Controller
{
    public function destroy(Request $request, DeleteAccount $delete): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm.']);

        $delete->run($request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Your account is closed. Your details are removed after 30 days; sign in before then to keep it.');
    }
}
