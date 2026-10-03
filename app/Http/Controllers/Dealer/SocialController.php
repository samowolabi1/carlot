<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Social\Actions\ConnectSocialAccounts;
use App\Domain\Social\Gateways\SocialPublisher;
use App\Domain\Social\Jobs\PublishToSocial;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialPost;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/** Connect Facebook/Instagram and auto-post new listings (TDD M9, seller API /social). */
class SocialController extends Controller
{
    /** @return array<string, mixed> the Settings → Social media props */
    public static function present(Lot $lot): array
    {
        return [
            'accounts' => SocialAccount::query()->orderBy('provider')->get()->map(fn (SocialAccount $a) => [
                'ulid' => $a->ulid,
                'provider' => $a->provider,
                'label' => SocialAccount::PROVIDERS[$a->provider] ?? $a->provider,
                'name' => $a->name,
                'auto_post' => $a->auto_post,
                'error' => $a->last_error,
                'expired' => $a->expired(),
            ]),
            'posts' => SocialPost::query()->with(['vehicle.make', 'vehicle.model', 'account'])->latest('id')->limit(8)->get()->map(fn (SocialPost $p) => [
                'key' => $p->account->ulid.'-'.$p->vehicle->ulid,
                'account' => $p->account->ulid,
                'vehicle' => $p->vehicle->ulid,
                'car' => $p->vehicle->title(),
                'provider' => $p->account->provider,
                'status' => $p->status,
                'error' => $p->error,
                'when' => ($p->posted_at ?? $p->created_at)->copy()->setTimezone($lot->timezone)->format('j M, H:i'),
            ]),
            'demo' => config('lotlink.social_driver') !== 'meta',
        ];
    }

    public function connect(Request $request, Lot $lot, SocialPublisher $publisher): RedirectResponse
    {
        Gate::authorize('update', $lot);

        $state = Str::random(40);
        $request->session()->put('social_oauth', ['state' => $state, 'lot' => $lot->slug]);

        return redirect()->away($publisher->authorizeUrl($state, route('social.callback')));
    }

    /** Meta sends the seller back here (one fixed redirect URL for every seller). */
    public function callback(Request $request, SocialPublisher $publisher, ConnectSocialAccounts $connect): RedirectResponse
    {
        $pending = $request->session()->pull('social_oauth');
        abort_unless(is_array($pending) && hash_equals((string) $pending['state'], (string) $request->query('state')), 403);

        $lot = Lot::where('slug', $pending['lot'])->firstOrFail();
        Gate::authorize('update', $lot);
        $back = route('dealer.settings', $lot).'#social';

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect($back)->with('error', 'Facebook connection was cancelled.');
        }

        try {
            $accounts = $publisher->accounts((string) $request->query('code'), route('social.callback'));
        } catch (Throwable $e) {
            report($e);

            return redirect($back)->with('error', "We couldn't connect to Facebook. Try again in a minute.");
        }

        $saved = $connect->run($lot, $request->user(), $accounts);

        return redirect($back)->with('success', 'Connected '.collect($saved)->pluck('name')->implode(' and ').'. New cars will be posted automatically.');
    }

    public function update(Request $request, Lot $lot, SocialAccount $socialAccount): RedirectResponse
    {
        Gate::authorize('update', $lot);
        $socialAccount->update($request->validate(['auto_post' => ['required', 'boolean']]));

        return back()->with('success', $socialAccount->auto_post ? 'Auto-post is on.' : 'Auto-post is off.');
    }

    public function destroy(Request $request, Lot $lot, SocialAccount $socialAccount): RedirectResponse
    {
        Gate::authorize('update', $lot);
        AuditLog::record('lot.social_disconnected', $lot, ['account' => "{$socialAccount->provider}: {$socialAccount->name}"], $request->user(), $lot->id);
        $socialAccount->delete();

        return back()->with('success', 'Disconnected. Posts already made stay on your Page.');
    }

    public function retry(Lot $lot, SocialAccount $socialAccount, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('update', $lot);
        abort_unless($socialAccount->lot_id === $lot->id && $vehicle->lot_id === $lot->id, 404);
        $failed = SocialPost::query()->where('social_account_id', $socialAccount->id)->where('vehicle_id', $vehicle->id)->where('status', 'failed')->exists();
        abort_unless($failed, 404);
        PublishToSocial::dispatch($vehicle->id, $socialAccount->id);

        return back()->with('success', 'Trying again.');
    }
}
