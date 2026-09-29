<?php

namespace App\Domain\Social\Gateways;

use App\Domain\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta Graph API (TDD M9): OAuth for a Facebook Page and its linked Instagram Business account,
 * then /{page-id}/photos and Instagram /{ig-user-id}/media + media_publish.
 */
class MetaSocialPublisher implements SocialPublisher
{
    private const SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish', 'business_management'];

    public function __construct(private readonly string $appId, private readonly string $appSecret, private readonly string $version = 'v21.0') {}

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return "https://www.facebook.com/{$this->version}/dialog/oauth?".http_build_query([
            'client_id' => $this->appId, 'redirect_uri' => $redirectUri, 'state' => $state, 'scope' => implode(',', self::SCOPES), 'response_type' => 'code',
        ]);
    }

    public function accounts(string $code, string $redirectUri): array
    {
        $short = $this->get('oauth/access_token', ['client_id' => $this->appId, 'client_secret' => $this->appSecret, 'redirect_uri' => $redirectUri, 'code' => $code]);
        // A long-lived user token gives Page tokens that don't expire.
        $long = $this->get('oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $this->appId, 'client_secret' => $this->appSecret, 'fb_exchange_token' => $short['access_token'] ?? '']);
        $pages = $this->get('me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'access_token' => $long['access_token'] ?? '']);

        $accounts = [];
        foreach ($pages['data'] ?? [] as $page) {
            $accounts[] = ['provider' => 'facebook', 'page_id' => (string) $page['id'], 'name' => (string) $page['name'], 'token' => (string) $page['access_token'], 'expires_at' => null];
            if (isset($page['instagram_business_account']['id'])) {
                $ig = $page['instagram_business_account'];
                $accounts[] = ['provider' => 'instagram', 'page_id' => (string) $ig['id'], 'name' => '@'.($ig['username'] ?? $page['name']), 'token' => (string) $page['access_token'], 'expires_at' => null];
            }
        }

        return $accounts;
    }

    public function publish(SocialAccount $account, string $imageUrl, string $caption): string
    {
        if ($account->provider === 'instagram') {
            $container = $this->post("{$account->page_id}/media", ['image_url' => $imageUrl, 'caption' => $caption, 'access_token' => $account->token]);
            $published = $this->post("{$account->page_id}/media_publish", ['creation_id' => $container['id'] ?? '', 'access_token' => $account->token]);

            return (string) ($published['id'] ?? throw new RuntimeException('Instagram did not return a post id.'));
        }

        $photo = $this->post("{$account->page_id}/photos", ['url' => $imageUrl, 'caption' => $caption, 'access_token' => $account->token]);

        return (string) ($photo['post_id'] ?? $photo['id'] ?? throw new RuntimeException('Facebook did not return a post id.'));
    }

    /** @param array<string, string> $query @return array<string, mixed> */
    private function get(string $path, array $query): array
    {
        return $this->check(Http::timeout(15)->get("https://graph.facebook.com/{$this->version}/{$path}", $query)->json() ?? []);
    }

    /** @param array<string, string> $data @return array<string, mixed> */
    private function post(string $path, array $data): array
    {
        return $this->check(Http::timeout(30)->asForm()->post("https://graph.facebook.com/{$this->version}/{$path}", $data)->json() ?? []);
    }

    /** @param array<string, mixed> $json @return array<string, mixed> */
    private function check(array $json): array
    {
        if (isset($json['error'])) {
            throw new RuntimeException('Meta: '.($json['error']['message'] ?? 'request failed'));
        }

        return $json;
    }
}
