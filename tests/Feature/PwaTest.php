<?php

it('serves an installable web app manifest', function () {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toStartWith('/')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('maskable');

    foreach ($manifest['icons'] as $icon) {
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

it('links the manifest and ships the service worker and offline page', function () {
    $this->get('/')->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('apple-touch-icon', false);

    expect(file_exists(public_path('sw.js')))->toBeTrue()
        ->and(file_get_contents(public_path('sw.js')))->toContain("'/offline.html'")
        ->and(file_exists(public_path('offline.html')))->toBeTrue();
});
