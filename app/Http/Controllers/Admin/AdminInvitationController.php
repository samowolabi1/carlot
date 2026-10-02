<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** An invited admin sets a password from the emailed link (signed, 7 days, only until they've joined), then goes to /admin for 2FA. */
class AdminInvitationController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        $this->ensureOpen($user);

        return Inertia::render('Auth/AdminInvitation', [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->adminRole()?->label(),
            'role_description' => $user->adminRole()?->description(),
            'action' => $request->fullUrl(),
        ])->withViewData(['meta' => ['title' => 'Join the LotLink admin team', 'robots' => 'noindex']]);
    }

    public function store(Request $request, User $user): SymfonyResponse
    {
        $this->ensureOpen($user);
        $data = $request->validate(['name' => Fields::personName(), 'password' => Fields::newPassword()]);

        $user->forceFill(['name' => trim($data['name']), 'password' => $data['password'], 'password_changed_at' => now(), 'email_verified_at' => now()])->save();
        AuditLog::record('admin.team_joined', $user, ['role' => $user->adminRole()?->value], $user);

        Auth::logout();
        Auth::login($user);
        $request->session()->regenerate();

        // The admin panel isn't an Inertia page: a full page load (then 2FA setup there).
        return Inertia::location(url('/admin'));
    }

    /** The link only works for an admin who hasn't set a password yet. */
    private function ensureOpen(User $user): void
    {
        abort_unless($user->isAdmin() && $user->password === null, 410, 'This invitation has already been used or was withdrawn. Ask an owner to send a new one.');
    }
}
