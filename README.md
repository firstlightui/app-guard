# Firstlight App Guard

App Guard gives NativePHP Mobile apps the pieces of an app lock that the platform doesn't provide.

- **Privacy cover:** blurs the app in the iOS app switcher; uses `FLAG_SECURE` on Android, which also blocks screenshots.
- **Return shield:** after a timeout you choose, covers the app with an opaque view before its first frame on return, so nothing shows until your lock screen is ready.
- **Lifecycle events:** `Backgrounded` and `Foregrounded`, with time away measured natively on a clock that keeps counting while the device sleeps.
- **Sensitive copy:** puts text on the clipboard device-only (no Universal Clipboard) and removes it after up to an hour.

Pair it with [`nativephp/mobile-biometrics`](https://nativephp.com/plugins/nativephp/mobile-biometrics) for a Face ID or fingerprint lock screen. The Firstlight UI docs have a complete, tested recipe: [Build an app lock screen](https://firstlightui.dev/docs/how-to/build-an-app-lock-screen). Free and MIT licensed.

Composer package `firstlightui/app-guard`, PHP namespace `FirstlightUI\AppGuard`, Kotlin package `dev.firstlightui.plugins.app_guard`. Requires PHP 8.4 or later, `nativephp/mobile` ^4.6, Android 10 (API 29) or later and iOS 18 or later.

## Bridge functions

Each facade method returns `true` when the native side applied the change. It returns `false` when the bridge function is missing (no native build, or the plugin is not registered) or the native side reported an error.

| Facade method | Bridge function | What it does |
|---|---|---|
| `AppGuard::setPrivacyCover(bool $enabled)` | `AppGuard.SetPrivacyCover` | Hides app content in the app switcher: a blur on iOS, `FLAG_SECURE` on Android. |
| `AppGuard::shieldAfter(?int $seconds)` | `AppGuard.SetShieldTimeout` | Covers the app on return after at least `$seconds` in the background, until `releaseShield()` is called. `null` turns the shield off; `0` shields every return. |
| `AppGuard::releaseShield()` | `AppGuard.ReleaseShield` | Fades out and removes the return shield. |
| `AppGuard::copySensitive(string $text, int $expiresInSeconds = 60)` | `AppGuard.CopySensitive` | Copies text that stays on this device and leaves the clipboard after `$expiresInSeconds` (1 to 3600). |

`shieldAfter()` rejects negative timeouts and `copySensitive()` rejects empty text or out-of-range expiries with `InvalidArgumentException`, before any native call.

## Events

| Event | When |
|---|---|
| `FirstlightUI\AppGuard\Events\Backgrounded` | The app moved to the background. PHP may not run again until the app returns, so do not rely on handling it promptly. |
| `FirstlightUI\AppGuard\Events\Foregrounded(int $secondsAway, bool $shielded)` | The app returned. `$secondsAway` is measured natively on a clock that keeps running while the device sleeps. `$shielded` is true while the shield covers the app. |

Both events implement `BroadcastsGlobally`, so they reach the active screen's `#[On]` listeners and app-wide `Event::listen` listeners.

If a `Foregrounded` event has `shielded = true`, whoever handles it must call `AppGuard::releaseShield()` once the app is safe to show. A lock screen, for example, releases the shield when it mounts; the 200 ms fade covers its first render.

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

Native state does not survive process death. Re-apply your stored settings with `setPrivacyCover()` and `shieldAfter()` on every cold start.

## Platform notes

| Capability | iOS | Android |
|---|---|---|
| `Backgrounded`, `Foregrounded` | UIKit `didEnterBackground` and `willEnterForeground` notifications | `Application.ActivityLifecycleCallbacks`: last activity stopped, first activity started |
| Privacy cover | Blur overlay added on resign-active, removed on become-active | `FLAG_SECURE` on the window. This also blocks screenshots, so tell users. |
| Return shield | Opaque view over every window, added on `willEnterForeground` | Opaque view over the decor view, added in `onActivityStarted` |
| Sensitive copy | `UIPasteboard` with `localOnly` and `expirationDate` | `ClipData` with `EXTRA_IS_SENSITIVE`, cleared after `expiresIn` if it is still the current clip |

These were verified against `nativephp/mobile` 4.6.0:

- The iOS shell declares `NativePHP.didEnterBackground` and `NativePHP.didBecomeActive` but never posts them, so the plugin observes UIKit's notifications directly.
- On Android, `NativePHPLifecycle` `onPause` also fires for in-app system dialogs. The plugin counts started activities instead, which tracks real trips to the background.
- Android's device-credential screen stops the app's activity, which counts as a trip to the background. Call `shieldAfter(null)` while your own biometric or passcode prompt is open, then restore the timeout afterwards.

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

