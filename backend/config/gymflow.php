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

];
