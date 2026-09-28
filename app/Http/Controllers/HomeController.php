<?php

namespace App\Http\Controllers;

use App\Domain\Lots\Models\Lot;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'lotCount' => Lot::active()->count(),
        ]);
    }
}
