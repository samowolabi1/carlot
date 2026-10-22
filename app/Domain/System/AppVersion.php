<?php

namespace App\Domain\System;

/**
 * Self-updating on cPanel without a terminal. scripts/build-cpanel.sh writes VERSION into each package; after a new
 * package is extracted over the old one, VERSION no longer matches storage/app/deployed-version, and:
 *  - bootstrap/app.php drops the previous release's cached config/routes/events (clearStaleCaches) so the new code boots;
 *  - public/index.php shows a short "updating" page (pending) instead of half-updated pages;
 *  - the cron job's schedule:run runs lotlink:auto-update first, which migrates (and installs, the first time),
 *    re-caches and records the version. It also re-caches the settings when .env is saved, and creates or fixes the
 *    first admin from ADMIN_EMAIL / ADMIN_PASSWORD (EnsureFirstAdmin), so no terminal is ever needed.
 * Plain PHP on purpose: it runs before the application (and its config) exists. Without VERSION (local, CI) it does nothing.
 */
final class AppVersion
{
    private const CACHE_FILES = ['config.php', 'routes-v7.php', 'events.php', 'packages.php', 'services.php'];

    /** The version of the uploaded code, or null outside a cPanel package. */
    public static function current(string $base): ?string
    {
        return self::read($base.'/VERSION');
    }

    /** The version the database and caches were last updated for. */
    public static function deployed(string $base): ?string
    {
        return self::read($base.'/storage/app/deployed-version');
    }

    /** A new package is uploaded but lotlink:auto-update hasn't finished it yet. */
    public static function pending(string $base): bool
    {
        $current = self::current($base);

        return $current !== null && $current !== self::deployed($base);
    }

    public static function markDeployed(string $base, string $version): void
    {
        @mkdir($base.'/storage/app', 0755, true);
        file_put_contents($base.'/storage/app/deployed-version', $version.PHP_EOL, LOCK_EX);
    }

    /** .env was saved since the cron job last applied it (its settings are cached, so they need refreshing). */
    public static function envChanged(string $base): bool
    {
        $env = $base.'/.env';

        return is_file($env) && sha1_file($env) !== self::read($base.'/storage/app/env-hash');
    }

    public static function markEnvApplied(string $base): void
    {
        if (is_file($base.'/.env')) {
            @file_put_contents($base.'/storage/app/env-hash', sha1_file($base.'/.env').PHP_EOL, LOCK_EX);
        }
    }

    /** APP_KEY as written in .env (quotes removed), or null when it's missing or empty. */
    public static function envKey(string $base): ?string
    {
        $env = @file_get_contents($base.'/.env');
        if (! is_string($env) || ! preg_match('/^\s*APP_KEY\s*=(.*)$/m', $env, $m)) {
            return null;
        }
        $value = trim(trim($m[1]), '"\'');

        return $value === '' ? null : $value;
    }

    /** .env exists but has no APP_KEY (for example it was re-created from the template). */
    public static function envKeyMissing(string $base): bool
    {
        return is_file($base.'/.env') && self::envKey($base) === null;
    }

    /** Write APP_KEY into .env, replacing the line (whatever its quotes) or adding it. */
    public static function writeEnvKey(string $base, string $key): bool
    {
        $path = $base.'/.env';
        $env = @file_get_contents($path);
        if (! is_string($env)) {
            return false;
        }
        $line = 'APP_KEY='.$key;
        $new = preg_match('/^\s*APP_KEY\s*=.*$/m', $env)
            ? (string) preg_replace_callback('/^\s*APP_KEY\s*=.*$/m', fn () => $line, $env, 1)
            : rtrim($env, PHP_EOL).PHP_EOL.$line.PHP_EOL;

        return @file_put_contents($path, $new, LOCK_EX) !== false;
    }

    /** An empty storage/app/reset-admin-password asks the cron job to reset the admin's password from .env. */
    public static function adminResetRequested(string $base): bool
    {
        return is_file($base.'/storage/app/reset-admin-password');
    }

    /** Something for the cron job's lotlink:auto-update to do: a new upload, a saved .env or an admin reset. */
    public static function needsAttention(string $base): bool
    {
        return self::current($base) !== null
            && (self::pending($base) || self::envChanged($base) || self::envKeyMissing($base) || self::adminResetRequested($base));
    }

    /** Once per upload: forget the previous release's bootstrap caches (they can name classes the new code no longer has). */
    public static function clearStaleCaches(string $base): void
    {
        $current = self::current($base);
        $stamp = $base.'/bootstrap/cache/.version';
        if ($current === null || self::read($stamp) === $current) {
            return;
        }
        foreach (self::CACHE_FILES as $file) {
            @unlink($base.'/bootstrap/cache/'.$file);
        }
        @file_put_contents($stamp, $current.PHP_EOL, LOCK_EX);
    }

    /** What visitors see for the minute or so between uploading and the cron job finishing the update. */
    public static function updatingPage(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta http-equiv="refresh" content="30"><title>CarYard is updating</title><style>'
            .'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#16302B;color:#fff;font:17px/1.5 system-ui,sans-serif;text-align:center;padding:24px}'
            .'h1{font-size:30px;margin:0 0 8px}h1 span{color:#F28C4B}p{opacity:.85;margin:0}</style></head><body><div>'
            .'<h1>Car<span>Yard</span> is updating</h1><p>We\'re adding improvements. Please try again in a minute.</p></div></body></html>';
    }

    /** Send the updating page (503) and stop. Used by public/index.php before Laravel starts. */
    public static function respondUpdating(): never
    {
        http_response_code(503);
        header('Retry-After: 60');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo self::updatingPage();
        exit;
    }

    private static function read(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $value = trim((string) @file_get_contents($path));

        return $value === '' ? null : $value;
    }
}
