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
        // whatsapp (falls back to SMS) or sms
        'channel' => env('OTP_CHANNEL', 'whatsapp'),
    ],

    // log (writes to storage/logs) or termii.
    'sms_driver' => env('SMS_DRIVER', 'log'),

    // log or meta (WhatsApp Business Cloud API). Templates to create in Meta: see README.
    'whatsapp_driver' => env('WHATSAPP_DRIVER', 'log'),

    'browse_rate_limit' => (int) env('BROWSE_RATE_LIMIT', 120),

    // Content Security Policy (TDD Security). On by default in production; `npm run dev` needs it off.
    'csp' => (bool) env('LOTLINK_CSP', env('APP_ENV') === 'production'),

    // Admins must use an authenticator app (TDD M1). On by default in production.
    'admin_2fa' => (bool) env('ADMIN_2FA', env('APP_ENV') === 'production'),

    // Facebook/Instagram auto-post (TDD M9): log | meta
    'social_driver' => env('SOCIAL_DRIVER', 'log'),

    // Finance pre-qualification hand-off (TDD M10): log | http
    'finance_partner' => [
        'driver' => env('FINANCE_PARTNER_DRIVER', 'log'),
        'code' => env('FINANCE_PARTNER_CODE', 'demo'),
        'name' => env('FINANCE_PARTNER_NAME', 'Demo Finance'),
        'url' => env('FINANCE_PARTNER_URL'),
        'key' => env('FINANCE_PARTNER_KEY'),
        'webhook_secret' => env('FINANCE_PARTNER_WEBHOOK_SECRET'),
    ],

    'invitation_ttl_days' => 7,

    // Billing (TDD M16). "sandbox" fakes the Paystack checkout so plans and spotlights can be
    // tried locally without keys; "paystack" is the real thing (keys in config/services.php).
    'billing' => [
        'driver' => env('PAYMENT_DRIVER', 'sandbox'),
        'trial_days' => 14,
        'grace_days' => 7,
        'free_plan' => 'free',
        // Placeholder prices in whole naira; set real ones after talking to the first lots.
        'spotlight' => [
            'car' => [7 => 5000, 14 => 9000, 30 => 18000],
            'featured_lot' => [7 => 15000, 14 => 27000, 30 => 50000],
        ],
    ],

    // Adverts lots buy from LotLink (placeholder prices in whole naira). Every advert is checked by
    // an admin before it runs; "slots" is how many can run at once.
    'adverts' => [
        'home_banner' => ['slots' => (int) env('ADS_HOME_SLOTS', 5), 'prices' => [7 => 40000, 14 => 75000, 30 => 140000]],
        'search_banner' => ['slots' => (int) env('ADS_SEARCH_SLOTS', 6), 'prices' => [7 => 20000, 14 => 36000, 30 => 65000]],
    ],

    // Share-card images (TDD M9), rendered on the media queue.
    'share_cards' => (bool) env('LOTLINK_SHARE_CARDS', true),

    // Budget tools (TDD M10). Estimates shown to buyers, never loan offers. Amounts in whole
    // naira. These are the defaults: admins can override them in /admin → Finance rates
    // (stored in platform_settings and laid over this array by FinanceRates::apply()).
    'finance' => [
        // Share of (income - commitments) that may go to a car loan.
        'affordability_ratio' => (float) env('FINANCE_AFFORDABILITY_RATIO', 0.35),
        // Defaults for "From ₦X/mo" on car pages and the calculators.
        'interest_rate' => (float) env('FINANCE_INTEREST_RATE', 24),
        'deposit_percent' => (int) env('FINANCE_DEPOSIT_PERCENT', 30),
        'tenor_months' => (int) env('FINANCE_TENOR_MONTHS', 36),
        'tenors' => [12, 24, 36, 48],
        // Cost of ownership, per year unless noted.
        'insurance_percent' => (float) env('FINANCE_INSURANCE_PERCENT', 4),
        'papers' => (int) env('FINANCE_PAPERS', 85000),
        'fuel_price' => (int) env('FINANCE_FUEL_PRICE', 1000), // per litre
        'km_per_month' => (int) env('FINANCE_KM_PER_MONTH', 1200),
        // Engine size (cc, up to) => km per litre.
        'km_per_litre' => [1600 => 14, 2500 => 11, 3500 => 8, 99999 => 6],
        // Car age (years, up to) => servicing per year.
        'servicing' => [3 => 250000, 8 => 400000, 99 => 600000],
    ],

    // Account numbers lots share with customers (Nigeria: 10-digit NUBAN). LotLink never takes
    // payments for cars; buyers pay the lot's account directly.
    'bank_account_pattern' => env('BANK_ACCOUNT_PATTERN', '/^\d{10}$/'),

    'maps' => [
        'browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
    ],

];
