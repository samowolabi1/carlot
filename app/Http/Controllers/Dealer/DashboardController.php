<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Lot $lot): Response
    {
        $lot->loadCount(['members', 'hours', 'invitations' => fn ($q) => $q->pending()]);

        return Inertia::render('Dealer/Dashboard', [
            'checklist' => [
                ['key' => 'business', 'label' => 'Business details', 'done' => true],
                ['key' => 'branding', 'label' => 'Logo and cover', 'done' => $lot->logo_path !== null],
                ['key' => 'location', 'label' => 'Pin your location', 'done' => $lot->hasLocation()],
                ['key' => 'hours', 'label' => 'Opening hours', 'done' => $lot->hours_count === 7],
                ['key' => 'staff', 'label' => 'Invite staff', 'done' => $lot->members_count > 1 || $lot->invitations_count > 0],
                ['key' => 'submit', 'label' => 'Submit for approval', 'done' => $lot->submitted_at !== null],
            ],
            'staffCount' => $lot->members_count,
        ]);
    }
}
