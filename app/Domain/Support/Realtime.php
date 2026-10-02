<?php

namespace App\Domain\Support;

use Illuminate\Broadcasting\BroadcastException;

/**
 * Live updates (chat, new leads, location) are extras: the page polls when no websocket server is there. So a
 * broadcast that can't reach Reverb or Pusher (not started, wrong port, down) is reported and the request carries on,
 * instead of failing a saved message, lead or loan application with a 500.
 */
final class Realtime
{
    public static function send(object $event, bool $toOthers = false): void
    {
        if ($toOthers && method_exists($event, 'dontBroadcastToCurrentUser')) {
            $event->dontBroadcastToCurrentUser();
        }

        try {
            event($event);
        } catch (BroadcastException $e) {
            report($e);
        }
    }
}
