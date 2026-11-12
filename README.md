# Firstlight App Guard

App Guard gives NativePHP Mobile apps the pieces of an app lock that the platform doesn't provide.

- **Privacy cover:** blurs the app in the iOS app switcher; uses `FLAG_SECURE` on Android, which also blocks screenshots.
- **Return shield:** after a timeout you choose, covers the app with an opaque view before its first frame on return, so nothing shows until your lock screen is ready. It shows your own image, or your splash screen, or your app icon.
- **Lifecycle events:** `Backgrounded` and `Foregrounded`, with time away measured natively on a clock that keeps counting while the device sleeps.
- **Sensitive copy:** puts text on the clipboard device-only (no Universal Clipboard) and removes it after up to an hour.
- **App lock:** a headless lock service, an optional PIN, and route middleware that keeps protected screens closed while the app is locked. Your app draws the lock and setup screens.

Pair it with [`nativephp/mobile-biometrics`](https://nativephp.com/plugins/nativephp/mobile-biometrics) for a Face ID or fingerprint lock screen. The Firstlight UI docs have a complete, tested recipe: [Build an app lock screen](https://firstlightui.dev/docs/how-to/build-an-app-lock-screen). Free and MIT licensed.

Composer package `firstlightui/app-guard`, PHP namespace `FirstlightUI\AppGuard`, Kotlin package `dev.firstlightui.plugins.app_guard`. Requires PHP 8.4 or later, `nativephp/mobile` ^4.7, Android 10 (API 29) or later and iOS 18 or later.

## Bridge functions

Each facade method returns `true` when the native side applied the change. It returns `false` when the bridge function is missing (no native build, or the plugin is not registered) or the native side reported an error.

| Facade method | Bridge function | What it does |
|---|---|---|
| `AppGuard::setPrivacyCover(bool $enabled)` | `AppGuard.SetPrivacyCover` | Hides app content in the app switcher: a blur on iOS, `FLAG_SECURE` on Android. |
| `AppGuard::shieldAfter(?int $seconds)` | `AppGuard.SetShieldTimeout` | Covers the app on return after at least `$seconds` in the background, until `releaseShield()` is called. `null` turns the shield off; `0` shields every return. |
| `AppGuard::releaseShield()` | `AppGuard.ReleaseShield` | Fades out and removes the return shield once the next screen has been drawn, or after 500 ms if none arrives. |
| `AppGuard::setShieldImage(?string $image = null, ?string $darkImage = null, string $fit = 'fill')` | `AppGuard.SetShieldImage` | Sets what the shield shows. See [Shield image](#shield-image). |
| `AppGuard::copySensitive(string $text, int $expiresInSeconds = 60)` | `AppGuard.CopySensitive` | Copies text that stays on this device and leaves the clipboard after `$expiresInSeconds` (1 to 3600). |

`shieldAfter()` rejects negative timeouts, `setShieldImage()` rejects a bad image or fit, and `copySensitive()` rejects empty text or out-of-range expiries with `InvalidArgumentException`, before any native call.

## Shield image

The shield shows, in this order:

1. Your image, set with `shield.image` in config (applied by `AppLock::boot()`) or `AppGuard::setShieldImage()`.
2. Your splash screen: the `public/splash.png` and `public/splash-dark.png` that NativePHP builds into the app, drawn like the splash.
3. Your app icon, at 120 points in the middle, with rounded corners on iOS and the launcher mask on Android.
4. A plain system background, white or black with the appearance.

```php
// config/app-guard.php
'shield' => [
    'image' => 'lock-cover.png',       // under public/
    'image_dark' => 'lock-cover-dark.png',
    'fit' => 'center',                 // or 'fill'
],
```

| Argument | Meaning |
|---|---|
| `$image` / `shield.image` | A path under `public/`, such as `brand/lock.png`. `null` uses the splash screen, then the icon. |
| `$darkImage` / `shield.image_dark` | Shown in dark mode. Needs `$image`. |
| `$fit` / `shield.fit` | `fill` covers the screen and crops, like the splash screen. `center` fits the image in a 120-point (dp) square in the middle of the system background, for a logo; supply at least 360 pixels. |

Absolute paths, URLs, paths containing `..`, files that don't exist and other fits throw `InvalidArgumentException`. The shield image only appears on return; the iOS app-switcher cover stays a blur, and Android's app switcher shows the app blank because of `FLAG_SECURE`. Under `native:jump` the device can't read the file from your machine, so the shield falls back to the splash screen.

## Events

| Event | When |
|---|---|
| `FirstlightUI\AppGuard\Events\Backgrounded` | The app moved to the background. PHP may not run again until the app returns, so do not rely on handling it promptly. |
| `FirstlightUI\AppGuard\Events\Foregrounded(int $secondsAway, bool $shielded)` | The app returned. `$secondsAway` is measured natively on a clock that keeps running while the device sleeps. `$shielded` is true while the shield covers the app. |

Both events implement `BroadcastsGlobally`, so they reach the active screen's `#[On]` listeners and app-wide `Event::listen` listeners.

If a `Foregrounded` event has `shielded = true`, whoever handles it must call `AppGuard::releaseShield()` once the app is safe to show. A lock screen, for example, releases the shield when it mounts: the shield stays up until the lock screen's first render has been drawn, then fades over 200 ms. A release that publishes no new screen fades after 500 ms.

## App lock

The app lock is PHP only: it holds the lock state, checks a PIN and guards routes. The lock screen and the PIN setup screen belong to your app, so they can look the way the rest of it does.

### How it fits together

- `FirstlightUI\AppGuard\AppLock`, a singleton, holds whether the app is unlocked. The flag lives in memory, so every cold start begins locked. `boot()` applies the privacy cover and the shield once per PHP process.
- The `app-lock` route middleware keeps a protected screen from mounting while the app is locked. nativephp/mobile 4.7 runs native route middleware before every in-app navigation (`ScreenGuard`), and the HTTP kernel runs it for the launch screen. A redirect from the middleware becomes a native replace onto your lock route.
- The `RequiresUnlock` trait covers the times middleware doesn't run. If a protected screen is showing when the app returns behind the shield, or shows again when the screen above it closes, the trait replaces it with the lock route.
- App Guard registers an app-wide `Foregrounded` listener. After `boot()` has run, a shielded return locks the app. Over a protected screen the shield stays up until your lock screen releases it. Over any other screen, or with the lock off, the listener releases the shield itself.
- `FirstlightUI\AppGuard\Pin` is an optional PIN. It stores only a `password_hash()`, in SecureStorage, along with a wrong-attempt count that survives restarts.

### Routes

```php
use App\NativeComponents\{LockScreen, PinSetupScreen, VaultScreen};
use Illuminate\Support\Facades\Route;

Route::middleware('app-lock')->group(function () {
    Route::native('/vault', VaultScreen::class)->name('vault');
    Route::native('/lock', LockScreen::class)->name('lock');            // always let through
    Route::native('/lock/setup', PinSetupScreen::class)->name('lock.setup'); // always let through
});
```

Apply the middleware to every screen with protected content. It always lets the lock and setup routes through, so putting them in the same group does no harm.

| App state | `app-lock` sends it to |
|---|---|
| Lock off (`LockSettings::enabled()` is false) | the screen |
| `require_pin` on and no PIN stored | `routes.setup` |
| Locked | `routes.lock` |
| Unlocked | the screen |

If secure storage can't be read, for example because the device is locked or the plugin is missing, a locked app goes to the lock route, never to setup. The middleware also remembers the URI it turned away. Read it back with `AppLock::pullIntended()`.

### Protected screens

```php
use FirstlightUI\AppGuard\Concerns\RequiresUnlock;

class VaultScreen extends NativeComponent
{
    use RequiresUnlock;

    protected function resumed(): void
    {
        // Runs instead of onResume(), and only while unlocked.
    }
}
```

`RequiresUnlock` defines `onResume()` and a `#[On(Foregrounded::class)]` handler. Put your own resume work in `resumed()`. A screen without the trait stays visible after a shielded return, because the listener releases the shield over it.

### The lock screen

Your lock screen releases the shield when it opens. The shield waits for the lock screen's first render to be drawn before it fades, so the screen it covered never shows. Once the person has proved who they are, the screen unlocks the app and goes back to the screen they were sent away from:

```php
use FirstlightUI\AppGuard\AppLock;
use FirstlightUI\AppGuard\Facades\AppGuard;
use FirstlightUI\AppGuard\Pin;

public function mount(): void
{
    AppGuard::releaseShield();
}

public function submit(string $digits): void
{
    $pin = app(Pin::class);

    if ($pin->verify($digits)) {
        app(AppLock::class)->unlock();
        $this->replace(app(AppLock::class)->pullIntended('/'));

        return;
    }

    if ($pin->exhausted()) {
        $this->signOut(); // your app's answer; call $pin->clear() as part of it
    }
}

// Around your own Face ID, Touch ID or passcode prompt:
app(AppLock::class)->suspendShield();
Biometrics::prompt()->id($id)->prompt();
// ...and once Biometric\Completed arrives, whatever its result:
app(AppLock::class)->resumeShield();
```

A setup screen calls `Pin::set()` and then `AppLock::unlock()`. Give users a way out of the lock screen, such as signing out. On Android, a device without an enrolled fingerprint or face can never pass a biometric prompt.

### API

`AppLock`:

| Method | What it does |
|---|---|
| `boot(): void` | Applies the privacy cover, the shield image and the shield timeout once per process. The middleware and the trait call it. |
| `booted(): bool` | Whether `boot()` has run in this process. |
| `isEnabled(): bool` | Whether the lock is on. |
| `isLocked(): bool` | True when the lock is on and the app hasn't been unlocked in this process. |
| `lock(): void` / `unlock(): void` | Lock the app, or unlock it once the person has proved who they are. |
| `foregrounded(bool $shielded): bool` | Handles a return from the background, as described above. Returns true when the return locked the app. The provider's listener calls it. |
| `suspendShield(): void` / `resumeShield(): void` | Turn the shield off around your own prompt, then restore it (it stays off while the lock is off). |
| `enable(): void` / `disable(): void` | Store the choice and apply the shield. `enable()` keeps the current session unlocked. |
| `timeout(): int` / `setTimeout(int $seconds): void` | Seconds in the background before a return needs the lock screen. 0 locks on every return. Negative values throw `InvalidArgumentException`. |
| `privacyCover(): bool` / `setPrivacyCover(bool $enabled): void` | Store the app-switcher cover choice and apply it. |
| `rememberIntended(string $uri): void` / `pullIntended(string $default = '/'): string` | The screen a locked app was sent away from. Reading it also forgets it. |

`Pin`:

| Method | What it does |
|---|---|
| `exists(): bool` | Whether a PIN is stored. |
| `valid(string $pin): bool` | Whether the PIN is ASCII digits only, from `pin.min_length` to `pin.max_length` long. |
| `set(string $pin): void` | Stores the first PIN with a fresh attempt budget. Throws `InvalidArgumentException` for an invalid PIN, or `LogicException` if a PIN already exists. |
| `verify(string $pin): bool` | Checks a PIN. A wrong PIN uses one attempt, and it is counted before the comparison. After `pin.max_attempts` wrong PINs, no PIN passes, the right one included. Returns false when no PIN exists. |
| `change(string $current, string $new): bool` | Replaces the PIN when `$current` is right. A wrong `$current` uses an attempt. |
| `clear(): void` | Removes the PIN, its attempt count and the biometrics preference, for example on sign-out. |
| `failedAttempts(): int` / `remainingAttempts(): int` / `exhausted(): bool` | The wrong-PIN budget. `exhausted()` is your cue to sign the person out. |
| `biometricsEnabled(): bool` / `setBiometricsEnabled(bool $enabled): void` | Whether the person chose to unlock with biometrics. App Guard only stores the choice. |
| `minLength()`, `maxLength()`, `maxAttempts()`: `int` | The configured limits. |

`Pin` reads with `SecureStorage::read()`, so it can tell "nothing stored" apart from "could not read". A locked device, a missing plugin or a failed bridge call throws `FirstlightUI\AppGuard\Exceptions\LockStorageException`, and a failed write throws it too. None of these ever reads as "no PIN" or as a wrong PIN. The PIN needs the [`nativephp/mobile-secure-storage`](https://nativephp.com/plugins/nativephp/mobile-secure-storage) plugin at runtime. App Guard doesn't require it in Composer, so an app without a PIN doesn't need it. The PIN itself is never stored, logged or sent anywhere except `password_hash()` and `password_verify()`, and every PIN parameter is marked `#[SensitiveParameter]`.

### Where lock choices are kept

`AppLock` reads and writes whether the lock is on, the timeout and the privacy cover through the `FirstlightUI\AppGuard\LockSettings` interface. By default this is `FileLockSettings`, a small JSON file at `app-guard.lock.settings_path`. Until the person makes a choice, it answers with the config values. These are preferences, not secrets, and they need to be readable before secure storage is. To keep them in your own settings store, bind your own implementation:

```php
$this->app->singleton(LockSettings::class, DeviceLockSettings::class);
```

`enabled()` answers whether the lock applies right now. An app that locks only while someone is signed in can return `$settingsSaysOn && $account->exists()`.

### Config

`config/app-guard.php`:

| Key | Default | Meaning |
|---|---|---|
| `lock.enabled` | `true` | Whether the lock is on until the person chooses |
| `lock.timeout` | `60` | Seconds in the background before a return locks; `0` locks every return |
| `lock.privacy_cover` | `true` | Whether the privacy cover is on until the person chooses |
| `lock.require_pin` | `false` | Whether `app-lock` sends the app to `routes.setup` while no PIN exists |
| `lock.settings_path` | `storage_path('app/app-guard/lock.json')` | Where `FileLockSettings` keeps the person's choices |
| `lock.routes.lock` | `'lock'` | Route name of your lock screen |
| `lock.routes.setup` | `'lock.setup'` | Route name of your PIN setup screen |
| `shield.image` / `shield.image_dark` | `null` | Shield image paths under `public/`; see [Shield image](#shield-image) |
| `shield.fit` | `'fill'` | `fill` or `center` |
| `pin.min_length` / `pin.max_length` | `4` / `6` | PIN length, in digits |
| `pin.max_attempts` | `5` | Wrong PINs, across restarts, before `exhausted()` |

### Migrating from the app lock recipe

If you followed [Build an app lock screen](https://firstlightui.dev/docs/how-to/build-an-app-lock-screen), your `App\Security\AppLock` maps onto `FirstlightUI\AppGuard\AppLock`:

| Recipe | App Guard 1.0 |
|---|---|
| `$this->app->singleton(AppLock::class)` and the `Event::listen(Foregrounded …)` in `AppServiceProvider` | Remove both. App Guard registers the singleton and the listener. |
| `AppLock::TIMEOUT` | `lock.timeout` in config, or `setTimeout()` |
| `boot()`, `isLocked()`, `lock()`, `unlock()`, `suspendShield()`, `resumeShield()` | The same names |
| `foregrounded($shielded)` | The same, called for you |
| Consigliere's `handleForeground()`, `applyShield()`, `applies()` | Drop the `handleForeground()` call from your screen base, because the provider's listener already runs `foregrounded()` and `RequiresUnlock` replaces the screen. Use `resumeShield()` and `isEnabled()` for the other two. |
| `DeviceSettings` lock keys | Your own `LockSettings` binding, or the default file |
| `LockedScreen::mount()` lock check | `->middleware('app-lock')` on the route, so the screen can use `mount()` again |
| `LockedScreen::onResume()` and its shielded `Foregrounded` handler | `use RequiresUnlock;`, with resume work in `resumed()` |
| `['return' => …]` data passed to the lock screen | `AppLock::pullIntended()` |

Then delete `app/Security/AppLock.php`, and point imports and test calls such as `app(AppLock::class)->unlock()` at `FirstlightUI\AppGuard\AppLock`. The `LockScreen` keeps its prompt logic.

## Installation

```bash
composer require firstlightui/app-guard
php artisan native:plugin:register firstlightui/app-guard
```

NativePHP compiles only the plugins the app lists. `native:plugin:register` adds the provider to `NativeServiceProvider::plugins()`; to add it by hand:

```php
use FirstlightUI\AppGuard\AppGuardServiceProvider;

public function plugins(): array
{
    return [
        AppGuardServiceProvider::class,
        // ...
    ];
}
```

Native state does not survive process death. If you use the [app lock](#app-lock), `AppLock::boot()` re-applies it for you. Otherwise re-apply your stored settings with `setPrivacyCover()` and `shieldAfter()` on every cold start.

To change the defaults, publish the config:

```bash
php artisan vendor:publish --tag=app-guard-config
```

## Platform notes

| Capability | iOS | Android |
|---|---|---|
| `Backgrounded`, `Foregrounded` | UIKit `didEnterBackground` and `willEnterForeground` notifications | `Application.ActivityLifecycleCallbacks`: last activity stopped, first activity started |
| Privacy cover | Blur overlay added on resign-active, removed on become-active | `FLAG_SECURE` on the window. This also blocks screenshots, so tell users. |
| Return shield | Opaque view over every window, added on `willEnterForeground`. Splash: the `LaunchImage` asset; icon: `CFBundleIcons`. | Opaque view over the decor view, added in `onActivityStarted`. Splash: the `splash` drawable; icon: the launcher icon. Your image is decoded on the main thread, sampled to the screen size. |
| Sensitive copy | `UIPasteboard` with `localOnly` and `expirationDate` | `ClipData` with `EXTRA_IS_SENSITIVE`, cleared after `expiresIn` if it is still the current clip |

These were verified against `nativephp/mobile` 4.6.0:

- The iOS shell declares `NativePHP.didEnterBackground` and `NativePHP.didBecomeActive` but never posts them, so the plugin observes UIKit's notifications directly.
- On Android, `NativePHPLifecycle` `onPause` also fires for in-app system dialogs. The plugin counts started activities instead, which tracks real trips to the background.
- Android's device-credential screen stops the app's activity, which counts as a trip to the background. Call `AppLock::suspendShield()` (or `shieldAfter(null)`) while your own biometric or passcode prompt is open, then `AppLock::resumeShield()` afterwards.

## Development

```bash
composer test      # Pest on Orchestra Testbench
composer lint      # Pint
composer analyse   # Larastan
```

The shield policy has the same test cases in Swift and Kotlin:

```bash
(cd resources/android && ./gradlew test compileHostCheckKotlin)   # JDK 21 and the Android 36 platform
swift test --package-path resources/ios
bin/typecheck-ios                                                 # needs Xcode
```

CI runs all of these except the iOS typecheck, and runs the PHP checks on both PHP 8.4 and 8.5.

## Releases

Tag `vX.Y.Z` on `main` and keep `version` in `nativephp.json` the same. Packagist picks up the tag.

