<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App lock
    |--------------------------------------------------------------------------
    |
    | enabled, timeout and privacy_cover are the defaults until the person
    | chooses otherwise. AppLock::enable(), disable(), setTimeout() and
    | setPrivacyCover() store their choices through the bound LockSettings,
    | by default a JSON file at settings_path. Bind your own LockSettings to
    | keep them elsewhere.
    |
    | timeout is how many seconds the app may spend in the background before a
    | return needs the lock screen. 0 locks on every return.
    |
    | require_pin makes the app-lock middleware send the person to the setup
    | route until a PIN exists. The PIN needs the native secure storage plugin
    | (nativephp/mobile-secure-storage).
    |
    | routes are route names: where the app-lock middleware and the
    | RequiresUnlock trait send a locked app, and where PIN setup lives.
    |
    */

    'lock' => [
        'enabled' => true,
        'timeout' => 60,
        'privacy_cover' => true,
        'require_pin' => false,
        'settings_path' => storage_path('app/app-guard/lock.json'),
        'routes' => [
            'lock' => 'lock',
            'setup' => 'lock.setup',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PIN
    |--------------------------------------------------------------------------
    |
    | A PIN is digits only, from min_length to max_length long. max_attempts
    | wrong PINs, counted across lock screens and restarts, exhaust the PIN:
    | no PIN is accepted after that, the right one included, until the app
    | clears it (typically by signing the person out).
    |
    */

    'pin' => [
        'min_length' => 4,
        'max_length' => 6,
        'max_attempts' => 5,
    ],

];
