<?php

// Behind Caddy, Cloudflare or a load balancer, trust its X-Forwarded-* headers so HTTPS (HSTS, secure cookies,
// signed URLs) is detected. TRUSTED_PROXIES: "*" or a comma-separated list of IPs. Read here, not in bootstrap/app.php,
// so it still applies after `config:cache` (which stops Laravel reading .env).
$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },
];
