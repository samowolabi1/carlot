<?php

namespace App\Http\Controllers;

use App\Domain\Lots\Actions\AcceptInvitation;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);
        $lot = Lot::findOrFail($invitation->lot_id);

        // Bring guests back here after they sign in.
        if (! $request->user()) {
            $request->session()->put('url.intended', $request->url());
        }

        return Inertia::render('Invitation', [
            'token' => $token,
            'lot' => ['name' => $lot->name, 'initials' => $lot->initials(), 'logo_url' => $lot->logo_url, 'city' => $lot->city],
            'role' => $invitation->role->label(),
            'pending' => $invitation->isPending(),
            'forCurrentUser' => $request->user() ? $invitation->isFor($request->user()) : null,
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $lot = $acceptInvitation->run($this->find($token), $request->user());

        return redirect()->route('dealer.dashboard', $lot)->with('success', "Welcome to {$lot->name}.");
    }

    private function find(string $token): LotInvitation
    {
        return LotInvitation::withoutGlobalScopes()->where('token', $token)->firstOrFail();
    }
}
