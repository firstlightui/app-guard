<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard;

use FirstlightUI\AppGuard\Concerns\RequiresUnlock;
use InvalidArgumentException;
use Native\Mobile\Edge\NativeComponent;

/**
 * Whether the app is locked, and the native guard state that goes with it.
 *
 * Bound as a singleton. The unlocked flag lives in memory only, so every cold start begins locked.
 * The person's choices (lock on or off, timeout, privacy cover) live in the bound LockSettings.
 */
final class AppLock
{
    private bool $booted = false;

    private bool $unlocked = false;

    private ?string $intended = null;

    public function __construct(
        private readonly LockSettings $settings,
        private readonly AppGuard $guard,
    ) {}

    /**
     * Applies the privacy cover, the shield image and the shield once per PHP process, because native state does not
     * survive process death. The app-lock middleware and RequiresUnlock call this for you.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $this->guard->setPrivacyCover($this->settings->privacyCover());
        $this->resumeShield();

        // A bad shield image must not leave the app unguarded: report it, and the shield falls back to the splash.
        try {
            $this->guard->setShieldImage(
                config('app-guard.shield.image'),
                config('app-guard.shield.image_dark'),
                (string) config('app-guard.shield.fit', 'fill'),
            );
        } catch (InvalidArgumentException $e) {
            report($e);
        }
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    public function isEnabled(): bool
    {
        return $this->settings->enabled();
    }

    public function isLocked(): bool
    {
        return $this->settings->enabled() && ! $this->unlocked;
    }

    public function lock(): void
    {
        $this->unlocked = false;
    }

    /**
     * Call once the person has proved who they are: a correct PIN, a passed biometric prompt, or a
     * PIN they have just set.
     */
    public function unlock(): void
    {
        $this->unlocked = true;
    }

    /**
     * Handles a return from the background. The service provider calls this for every Foregrounded
     * event once boot() has run. Returns true when this return locked the app.
     *
     * A shielded return locks the app. A screen using RequiresUnlock then replaces itself with the
     * lock screen, which releases the shield when it opens; on any other screen, or when the lock is
     * off, the shield is released here, because whoever receives a shielded return must release it.
     */
    public function foregrounded(bool $shielded): bool
    {
        if (! $shielded) {
            return false;
        }

        if (! $this->settings->enabled()) {
            $this->guard->releaseShield();

            return false;
        }

        $this->lock();

        if (! self::screenRequiresUnlock(NativeComponent::active())) {
            $this->guard->releaseShield();
        }

        return true;
    }

    /**
     * Turns the shield off while the app's own biometric or passcode prompt is open. Android's
     * device-credential screen stops the app, which would otherwise count as a trip to the background.
     */
    public function suspendShield(): void
    {
        $this->guard->shieldAfter(null);
    }

    /**
     * Applies the shield timeout again, or keeps the shield off while the lock is off.
     */
    public function resumeShield(): void
    {
        $this->guard->shieldAfter($this->settings->enabled() ? $this->settings->timeout() : null);
    }

    /**
     * Turns the lock on. Turning it on from inside the app keeps this session unlocked.
     */
    public function enable(): void
    {
        $this->settings->setEnabled(true);
        $this->unlock();
        $this->resumeShield();
    }

    public function disable(): void
    {
        $this->settings->setEnabled(false);
        $this->resumeShield();
    }

    public function timeout(): int
    {
        return $this->settings->timeout();
    }

    /**
     * Seconds in the background before a return needs the lock screen. 0 locks on every return.
     */
    public function setTimeout(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('The lock timeout cannot be negative.');
        }

        $this->settings->setTimeout($seconds);
        $this->resumeShield();
    }

    public function privacyCover(): bool
    {
        return $this->settings->privacyCover();
    }

    public function setPrivacyCover(bool $enabled): void
    {
        $this->settings->setPrivacyCover($enabled);
        $this->guard->setPrivacyCover($enabled);
    }

    /**
     * Remembers the screen a locked app was sent away from, for the lock screen to return to.
     */
    public function rememberIntended(string $uri): void
    {
        $this->intended = $uri === '' ? null : $uri;
    }

    /**
     * The screen to open after unlocking, forgotten once read.
     */
    public function pullIntended(string $default = '/'): string
    {
        $intended = $this->intended ?? $default;
        $this->intended = null;

        return $intended;
    }

    private static function screenRequiresUnlock(?NativeComponent $screen): bool
    {
        return $screen !== null && in_array(RequiresUnlock::class, class_uses_recursive($screen), true);
    }
}
