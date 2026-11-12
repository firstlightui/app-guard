# Changelog

## 1.0.0

App Guard now includes a headless app lock, so apps share one reviewed lock instead of each keeping its own. The existing bridge functions and events are unchanged.

### Added

- `FirstlightUI\AppGuard\AppLock`, a singleton for the lock state. The unlocked flag is kept in memory, so every cold start begins locked. It applies the privacy cover and shield once per process and handles shielded and unshielded returns. It can suspend and restore the shield around your own prompt, stores the lock on/off choice, the timeout and the privacy cover, and remembers which screen a locked app was sent away from.
- `FirstlightUI\AppGuard\LockSettings`, an interface for where those choices are kept. The default, `FileLockSettings`, is a JSON file. Bind your own to use your app's settings store.
- `FirstlightUI\AppGuard\Pin`, an optional digits-only PIN with a configurable length. It stores only a `password_hash()`, in SecureStorage. It can be changed with the current PIN and cleared. A wrong-attempt budget survives restarts, and once it is spent no PIN passes. It also stores a biometrics preference. When secure storage can't be read, it throws `LockStorageException` instead of reporting "no PIN".
- The `app-lock` route middleware (`Http\Middleware\EnsureUnlocked`). It sends a locked app to your lock route, or to your setup route while a required PIN is missing. It runs through nativephp/mobile 4.7's `ScreenGuard` before every in-app navigation.
- The `Concerns\RequiresUnlock` trait for protected screens. On a shielded return, or when the screen shows again while locked, it replaces the screen with the lock route.
- An app-wide `Foregrounded` listener, active once `AppLock::boot()` has run.
- A shield image. `AppGuard::setShieldImage()`, the `AppGuard.SetShieldImage` bridge function and the `shield.image`, `shield.image_dark` and `shield.fit` config set what the return shield shows. `fill` covers the screen like the splash screen; `center` fits a logo in a 120-point square. Without an image the shield shows the app's splash screen, else its icon, else a plain background, which is all it showed before. The iOS app-switcher cover stays a blur.
- `config/app-guard.php`, publishable with `--tag=app-guard-config`.

### Changed

- Requires `nativephp/mobile` ^4.7, the first release with `ScreenGuard`.

### Migrating from the app lock recipe

Your `App\Security\AppLock` maps onto `FirstlightUI\AppGuard\AppLock` with the same method names: `boot`, `isLocked`, `lock`, `unlock`, `foregrounded`, `suspendShield` and `resumeShield`. Remove your singleton binding and your `Foregrounded` listener, because App Guard registers both. Move `TIMEOUT` to `app-guard.lock.timeout`. Replace the `LockedScreen` base with `->middleware('app-lock')` on protected routes and `use RequiresUnlock;` on their screens. Read the return screen with `AppLock::pullIntended()` instead of navigation data. See "Migrating from the app lock recipe" in the README.
