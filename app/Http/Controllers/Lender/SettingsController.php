<?php

namespace App\Http\Controllers\Lender;

use App\Domain\Finance\Actions\SaveLender;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderType;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Support\LenderRules;
use App\Domain\Support\Fields;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The lender's profile, loan product and how applications reach it (portal or its own API). Admins only. */
class SettingsController extends Controller
{
    public function edit(Request $request, Lender $lender): Response
    {
        return Inertia::render('Lender/Settings', [
            'values' => [
                'name' => $lender->name,
                'licence_type' => $lender->licence_type->value,
                'licence_number' => $lender->licence_number,
                'contact_name' => $lender->contact_name,
                'contact_email' => $lender->contact_email,
                'contact_phone' => PhoneNumber::display($lender->contact_phone),
                'website' => $lender->website ?? '',
                'about' => $lender->about ?? '',
                'rate' => $lender->rate_bp / 100,
                'min_amount' => (string) intdiv($lender->min_amount, 100),
                'max_amount' => (string) intdiv($lender->max_amount, 100),
                'min_deposit_percent' => $lender->min_deposit_percent,
                'tenors' => $lender->tenors,
                'states' => $lender->states ?? [],
                'integration' => $lender->integration->value === 'api' ? 'api' : 'portal',
                'api_url' => $lender->api_url ?? '',
            ],
            'has_key' => filled($lender->api_key),
            'webhook' => ['url' => route('webhooks.finance', $lender), 'secret' => session('lender_secret')],
            'has_secret' => filled($lender->webhook_secret),
            'demo' => $lender->integration->value === 'demo',
            'types' => LenderType::options(),
            'tenors' => Lender::TENORS,
            'can_manage' => $lender->roleOf($request->user()) === LenderRole::Admin,
        ])->withViewData(['meta' => ['title' => 'Settings — '.$lender->name, 'robots' => 'noindex']]);
    }

    public function update(Request $request, Lender $lender, SaveLender $save): RedirectResponse
    {
        abort_unless($lender->roleOf($request->user()) === LenderRole::Admin, 403);
        foreach (LenderRules::moneyKeys() as $key) {
            $request->merge([$key => Fields::cleanMoney($request->input($key))]);
        }
        $rules = [...LenderRules::profile(), ...LenderRules::product(), ...($lender->integration->value === 'demo' ? [] : LenderRules::integration())];
        // The name and licence are what LotLink checked: ask support to change them.
        unset($rules['name'], $rules['licence_type'], $rules['licence_number']);
        $data = $request->validate($rules);
        $data['states'] ??= [];

        $hadSecret = filled($lender->webhook_secret);
        $save->run($lender, $data, $request->user());

        $redirect = back()->with('success', 'Saved.');

        return ! $hadSecret && filled($lender->webhook_secret) ? $redirect->with('lender_secret', $lender->webhook_secret) : $redirect;
    }

    public function secret(Request $request, Lender $lender, SaveLender $save): RedirectResponse
    {
        abort_unless($lender->roleOf($request->user()) === LenderRole::Admin, 403);
        $secret = $save->rotateSecret($lender, $request->user());

        return back()->with('success', 'New webhook secret made. Copy it now: it is only shown once.')->with('lender_secret', $secret);
    }
}
