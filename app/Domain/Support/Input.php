<?php

namespace App\Domain\Support;

use Illuminate\Http\Request;

/**
 * Reads one request value as plain text. Anyone can send `phone[]=…` or `?city[x]=…`, and casting those arrays
 * ((string) $request->input(…), $request->string(…)) is a PHP warning, which is a 500 on the server. Read text through
 * here instead: anything that isn't a single scalar value comes back as the default, so validation or the page simply
 * treats it as missing.
 */
final class Input
{
    /** From the body or the query string, like $request->input(). */
    public static function text(Request $request, string $key, string $default = ''): string
    {
        return self::scalar($request->input($key), $default);
    }

    /** From the query string only, like $request->query(). */
    public static function query(Request $request, string $key, string $default = ''): string
    {
        return self::scalar($request->query($key), $default);
    }

    public static function scalar(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
