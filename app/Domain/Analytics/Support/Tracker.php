<?php

namespace App\Domain\Analytics\Support;

use App\Domain\Analytics\Jobs\TrackEvent;
use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Records views, saves, shares, leads and bookings for seller analytics (TDD M15). Bots and
 * link previews are skipped; a visitor viewing the same car again within 30 minutes counts once.
 */
final class Tracker
{
    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|headless|lighthouse|curl|wget|python-requests|httpclient/i';

    public static function isBot(?string $userAgent): bool
    {
        return $userAgent === null || $userAgent === '' || preg_match(self::BOTS, $userAgent) === 1;
    }

    public static function view(Request $request, Vehicle $vehicle, ?string $channel = null): void
    {
        if (self::isBot($request->userAgent())) {
            return;
        }

        // Guests by address and browser, so a new session cookie doesn't count as a new visitor.
        $visitor = $request->user()->id ?? sha1($request->ip().'|'.$request->userAgent());
        if (! Cache::add("analytics:view:{$vehicle->id}:{$visitor}", 1, now()->addMinutes(30))) {
            return;
        }

        self::record('view', $vehicle->lot_id, $vehicle->id, $channel);
    }

    /** @param string $type one of AnalyticsEvent::TYPES */
    public static function record(string $type, int $lotId, ?int $vehicleId, ?string $channel = null): void
    {
        TrackEvent::dispatch($lotId, $vehicleId, $type, $channel, now());
    }
}
