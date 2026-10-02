<?php

namespace App\Http\Controllers\Lender;

use App\Domain\Admin\AdminArea;
use App\Domain\Finance\Models\Lender;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A lender's licence copy, for LotLink admins checking it, through a short-lived signed link. */
class LicenceController extends Controller
{
    public function __invoke(Request $request, Lender $lender): StreamedResponse
    {
        abort_unless($request->user()->adminCan(AdminArea::Approvals), 404);
        $disk = Storage::disk(Lender::DISK);
        abort_unless($lender->licence_path && $disk->exists($lender->licence_path), 404);

        return $disk->response($lender->licence_path, "{$lender->slug}-licence.".pathinfo($lender->licence_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
