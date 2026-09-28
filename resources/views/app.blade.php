<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#16302B">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="LotLink">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <title inertia>{{ $meta['title'] ?? config('app.name', 'LotLink') }}</title>
        {{-- Server-rendered so link previews (WhatsApp, Facebook, X) and search engines see them without JavaScript. --}}
        @isset($meta)
            @if (! empty($meta['description']))
                <meta name="description" content="{{ $meta['description'] }}">
            @endif
            @if (! empty($meta['robots']))
                <meta name="robots" content="{{ $meta['robots'] }}">
            @endif
            <link rel="canonical" href="{{ $meta['url'] ?? url()->current() }}">
            <meta property="og:site_name" content="{{ config('app.name', 'LotLink') }}">
            <meta property="og:type" content="{{ $meta['type'] ?? 'website' }}">
            <meta property="og:title" content="{{ $meta['title'] }}">
            @if (! empty($meta['description']))
                <meta property="og:description" content="{{ $meta['description'] }}">
            @endif
            <meta property="og:url" content="{{ $meta['url'] ?? url()->current() }}">
            @if (! empty($meta['image']))
                <meta property="og:image" content="{{ $meta['image'] }}">
                <meta name="twitter:card" content="summary_large_image">
            @else
                <meta name="twitter:card" content="summary">
            @endif
            <meta name="twitter:title" content="{{ $meta['title'] }}">
        @endisset
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap">
        @routes
        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
