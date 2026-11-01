# nativephp-app-guard

A NativePHP Mobile plugin for apps that handle secrets. It provides:

- app lifecycle events (`Backgrounded`, `Foregrounded`)
- an app-switcher privacy cover
- a return shield that covers the app after it has been in the background for a set time
- clipboard copies that stay on the device and expire

Composer package `wojt-janowski/nativephp-app-guard`, PHP namespace `WojtJanowski\AppGuard`, Kotlin package `com.wojtjanowski.plugins.appguard`. Requires PHP 8.4 or later, `nativephp/mobile` ^4.6, Android 10 (API 29) or later and iOS 18 or later.

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
| `WojtJanowski\AppGuard\Events\Backgrounded` | The app moved to the background. PHP may not run again until the app returns, so do not rely on handling it promptly. |
| `WojtJanowski\AppGuard\Events\Foregrounded(int $secondsAway, bool $shielded)` | The app returned. `$secondsAway` is measured natively on a clock that keeps running while the device sleeps. `$shielded` is true while the shield covers the app. |

Both events implement `BroadcastsGlobally`, so they reach the active screen's `#[On]` listeners and app-wide `Event::listen` listeners.

If a `Foregrounded` event has `shielded = true`, whoever handles it must call `AppGuard::releaseShield()` once the app is safe to show. A lock screen, for example, releases the shield when it mounts; the 200 ms fade covers its first render.

## Installation

The package is private. Install it from Clinically's Packistry. Add the repository to the app's `composer.json`:

```json
"repositories": [
    {
        "name": "clinically",
        "type": "composer",
        "url": "https://repo.clindev.au"
    }
]
```

Then require it. Your `COMPOSER_AUTH` must hold a bearer token for `repo.clindev.au`.

```bash
composer require wojt-janowski/nativephp-app-guard:^0.1
```

NativePHP compiles only the plugins the app lists, so add the provider to `NativeServiceProvider::plugins()`:

```php
use WojtJanowski\AppGuard\AppGuardServiceProvider;

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

CI runs all of these except the iOS typecheck, and runs the PHP suite on both PHP 8.5 and 8.4.

## Releases

Tag `vX.Y.Z` on `main` and keep `version` in `nativephp.json` the same. Packistry imports the tag and serves it from `repo.clindev.au`.

## Origin

Extracted from wojt-janowski/forge-control at 1bef64d28195f3b9651309bd0ee8050c7ed1355a (`plugins/app-guard`), with its history.
