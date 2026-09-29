<?php

namespace App\Http\Controllers;

use App\Domain\Admin\Impersonation;
use Illuminate\Http\RedirectResponse;

/** Back to the admin panel after "Log in as" (TDD M17). */
class ImpersonationController extends Controller
{
    public function destroy(Impersonation $impersonation): RedirectResponse
    {
        return $impersonation->stop() ? redirect('/admin') : redirect()->route('home');
    }
}
