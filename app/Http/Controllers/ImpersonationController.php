<?php

namespace App\Http\Controllers;

use App\Domain\Admin\Impersonation;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Back to the admin panel after "Log in as" (TDD M17). */
class ImpersonationController extends Controller
{
    /**
     * A full page load, not an Inertia visit: /admin isn't an Inertia page, and following the
     * redirect inside Inertia showed the admin panel in a pop-up over the seller's page.
     */
    public function destroy(Impersonation $impersonation): Response
    {
        return Inertia::location($impersonation->stop() ? url('/admin') : route('home'));
    }
}
