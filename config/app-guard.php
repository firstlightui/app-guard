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
    | Shield image
    |--------------------------------------------------------------------------
    |
    | What the return shield shows while it covers the app. image and
    | image_dark are paths under public/, such as 'lock-cover.png'. With no
    | image the shield shows the app's splash screen (public/splash.png and
    | splash-dark.png, which NativePHP builds into the app), else its icon,
    | else a plain background.
    |
    | fit is 'fill', which covers the screen and crops like the splash, or
    | 'center', which fits the image in a 120-point square in the middle of
    | the system background, for a logo.
    |
    | The iOS app-switcher cover stays a blur. Android shows nothing there,
    | because FLAG_SECURE blanks the app in the app switcher.
    |
    */

    'shield' => [
        'image' => null,
        'image_dark' => null,
        'fit' => 'fill',
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
