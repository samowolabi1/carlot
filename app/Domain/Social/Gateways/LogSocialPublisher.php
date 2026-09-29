<?php

namespace App\Domain\Social\Gateways;

use App\Domain\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** SOCIAL_DRIVER=log: a demo Page and Instagram account, and posts written to the log. */
class LogSocialPublisher implements SocialPublisher
{
    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $redirectUri.'?'.http_build_query(['code' => 'demo', 'state' => $state]);
    }

    public function accounts(string $code, string $redirectUri): array
    {
        return [
            ['provider' => 'facebook', 'page_id' => '1000'.random_int(100000, 999999), 'name' => 'Demo Facebook Page', 'token' => Str::random(40), 'expires_at' => null],
            ['provider' => 'instagram', 'page_id' => '1784'.random_int(100000, 999999), 'name' => '@demo_lot', 'token' => Str::random(40), 'expires_at' => null],
        ];
    }

    public function publish(SocialAccount $account, string $imageUrl, string $caption): string
    {
        Log::info("Social post to {$account->provider} {$account->name}", ['image' => $imageUrl, 'caption' => $caption]);

        return 'log_'.Str::lower(Str::random(12));
    }
}
