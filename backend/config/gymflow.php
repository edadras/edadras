<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported locales
    |--------------------------------------------------------------------------
    | Every user facing string is translated from the database. RTL locales
    | are flagged so the apps can flip their layout direction.
    */

    'locales' => [
        'fa' => ['name' => 'فارسی', 'native' => 'فارسی', 'dir' => 'rtl', 'flag' => '🇮🇷'],
        'tr' => ['name' => 'Türkçe', 'native' => 'Türkçe', 'dir' => 'ltr', 'flag' => '🇹🇷'],
        'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'flag' => '🇬🇧'],
    ],

    'default_locale' => 'fa',

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    | The glassmorphism theme shared by both mobile apps and the admin panel.
    */

    'brand' => [
        'primary' => '#5EF38C',
        'surface' => '#FFFFFF',
        'background' => '#0D0F12',
    ],

    /*
    |--------------------------------------------------------------------------
    | Check-in
    |--------------------------------------------------------------------------
    */

    'checkin' => [
        // A scan of the same member inside this window is treated as a mistake.
        'duplicate_window_minutes' => 2,
        // Auto close entries left open when the club shuts for the night.
        'auto_checkout_after_hours' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Renewal reminders
    |--------------------------------------------------------------------------
    */

    'renewal' => [
        'remind_days_before' => [7, 3, 1],
        'remind_sessions_left' => [3, 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI module
    |--------------------------------------------------------------------------
    | Without an API key the platform falls back to the statistical engine,
    | so churn scores and forecasts keep working offline.
    */

    'ai' => [
        'enabled' => env('GYMFLOW_AI_ENABLED', true),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Messaging
    |--------------------------------------------------------------------------
    | Every channel falls back to the log driver until it is configured, so a
    | campaign always runs end to end and nothing leaves the building by
    | accident. A club can override any of these from its own settings under
    | the `messaging.<channel>` key, which is how one gym brings its own SMS
    | gateway without touching anyone else's.
    */

    'messaging' => [

        // Above this many recipients the send goes to the queue.
        'queue_above' => (int) env('GYMFLOW_CAMPAIGN_QUEUE_ABOVE', 25),

        'log_channel' => env('GYMFLOW_MESSAGING_LOG', 'stack'),

        'channels' => [

            'sms' => [
                'url' => env('SMS_URL'),
                'token' => env('SMS_TOKEN'),
                'sender' => env('SMS_SENDER'),
                'to_field' => env('SMS_TO_FIELD', 'to'),
                'text_field' => env('SMS_TEXT_FIELD', 'text'),
                'from_field' => env('SMS_FROM_FIELD', 'from'),
                'reference_path' => env('SMS_REFERENCE_PATH', 'messageId'),
            ],

            'whatsapp' => [
                'token' => env('WHATSAPP_TOKEN'),
                'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
                'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
                'template' => env('WHATSAPP_TEMPLATE'),
                'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE'),
            ],

            'telegram' => [
                'token' => env('TELEGRAM_BOT_TOKEN'),
                'broadcast_chat_id' => env('TELEGRAM_CHAT_ID'),
                'parse_mode' => env('TELEGRAM_PARSE_MODE', 'HTML'),
            ],

            'email' => [
                'from_name' => env('MAIL_FROM_NAME'),
            ],

            'push' => [
                // Either the service account JSON itself or a path to it.
                'credentials' => env('FCM_CREDENTIALS'),
                'project_id' => env('FCM_PROJECT_ID'),
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Online payments
    |--------------------------------------------------------------------------
    | Until a club connects a real gateway the sandbox driver answers, so the
    | whole checkout — start, redirect, verify, receipt — can be walked
    | through without a bank. It never moves money.
    */

    'payments' => [

        'default' => env('GYMFLOW_PAYMENT_GATEWAY', 'sandbox'),

        'gateways' => [

            'zarinpal' => [
                'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
                'sandbox' => (bool) env('ZARINPAL_SANDBOX', false),
                // IRT sends Toman figures as Rial; IRR sends them unchanged.
                'currency' => env('ZARINPAL_CURRENCY', 'IRT'),
            ],

            'stripe' => [
                'secret' => env('STRIPE_SECRET'),
                'currency' => env('STRIPE_CURRENCY', 'usd'),
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    | The nightly archive of the database and everything uploaded. Off by
    | default so a fresh install does not start writing archives nobody asked
    | for; the schedule below only runs when it is switched on.
    */

    'backup' => [
        'enabled' => (bool) env('GYMFLOW_BACKUP_ENABLED', false),
        'disk' => env('GYMFLOW_BACKUP_DISK', 'local'),
        'path' => env('GYMFLOW_BACKUP_PATH', 'backups'),
        // Archives to keep before the oldest is pruned.
        'keep' => (int) env('GYMFLOW_BACKUP_KEEP', 14),
        'time' => env('GYMFLOW_BACKUP_TIME', '03:30'),
    ],

];
