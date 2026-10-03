<?php

namespace App\Domain\Social\Gateways;

use App\Domain\Social\Models\SocialAccount;

/** Facebook/Instagram behind an interface (TDD: external services), so tests and Laragon never call Meta. */
interface SocialPublisher
{
    /** Where to send the seller to connect their Page. */
    public function authorizeUrl(string $state, string $redirectUri): string;

    /**
     * Swap the OAuth code for the Pages (and linked Instagram accounts) the seller manages.
     *
     * @return list<array{provider: string, page_id: string, name: string, token: string, expires_at: ?\DateTimeInterface}>
     */
    public function accounts(string $code, string $redirectUri): array;

    /** Post a photo with a caption; returns the platform's post id. Throws on failure. */
    public function publish(SocialAccount $account, string $imageUrl, string $caption): string;
}
