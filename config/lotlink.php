<?php

return [

    // Region used to read phone numbers typed without a country code.
    'default_region' => env('LOTLINK_DEFAULT_REGION', 'NG'),

    'currency' => env('LOTLINK_CURRENCY', 'NGN'),

    'timezone' => env('LOTLINK_TIMEZONE', 'Africa/Lagos'),

    // New lots start on this plan until billing (sprint S7) lands. Starter matches
    // the launch offer of 3 months free on Starter for the first lots.
    'default_plan' => env('LOTLINK_DEFAULT_PLAN', 'starter'),

    // Disk for logos, covers and (later) car photos. Cloudflare R2 in production.
    'media_disk' => env('LOTLINK_MEDIA_DISK', 'public'),

    // Private disk for original uploads before processing. When it is an s3 disk (R2),
    // browsers upload directly with pre-signed URLs; otherwise through the app.
    'upload_disk' => env('LOTLINK_UPLOAD_DISK', 'local'),

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'max_sends' => 3,
        'send_window_minutes' => 15,
    ],

    // log (writes to storage/logs) or termii.
    'sms_driver' => env('SMS_DRIVER', 'log'),

    'invitation_ttl_days' => 7,

    'maps' => [
        'browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
    ],

];
