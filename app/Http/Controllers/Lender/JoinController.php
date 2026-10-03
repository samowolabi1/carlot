<?php

namespace App\Http\Controllers\Lender;

use App\Domain\Finance\Actions\ApplyToBeLender;
use App\Domain\Finance\Enums\LenderType;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Support\LenderRules;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "Lend with CarYard": what lenders get, and the sign-up form (an admin approves each lender). */
class JoinController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Lender/Join', [
            'mine' => $user ? $user->lenders()->orderBy('name')->get()->map(fn (Lender $l) => [
                'slug' => $l->slug, 'name' => $l->name, 'status' => $l->status->value, 'status_label' => $l->status->label(), 'note' => $l->review_note,
            ])->values() : [],
            'types' => LenderType::options(),
            'tenors' => Lender::TENORS,
            'licence' => ['max_kb' => ApplyToBeLender::LICENCE_KB, 'mimes' => ApplyToBeLender::LICENCE_MIMES],
            'min_loan' => LenderRules::MIN_LOAN,
        ])->withViewData(['meta' => [
            'title' => 'Lend with CarYard — car loans for buyers across Nigeria',
            'description' => 'Banks and finance companies: receive car loan applications from buyers on CarYard, with their consent, and work them in one place.',
        ]]);
    }

    public function store(Request $request, ApplyToBeLender $apply): RedirectResponse
    {
        foreach (LenderRules::moneyKeys() as $key) {
            $request->merge([$key => Fields::cleanMoney($request->input($key))]);
        }
        $data = $request->validate([
            ...LenderRules::profile(),
            ...LenderRules::product(),
            'licence' => ['required', 'file', 'max:'.ApplyToBeLender::LICENCE_KB, 'mimes:'.implode(',', ApplyToBeLender::LICENCE_MIMES)],
            'agree' => ['accepted'],
        ], ['agree.accepted' => 'Tick the box to agree to the lender terms.', 'licence.required' => 'Add a copy of your licence (or, for an individual lender, your ID).']);
        unset($data['licence'], $data['agree']);

        $lender = $apply->run($request->user(), $data, $request->file('licence'));

        return to_route('lender.dashboard', $lender)->with('success', 'Thanks. We\'ll check your details and licence, usually within two working days.');
    }
}
