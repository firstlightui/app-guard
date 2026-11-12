<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Concerns;

use FirstlightUI\AppGuard\AppLock;
use FirstlightUI\AppGuard\Events\Foregrounded;
use Native\Mobile\Attributes\On;

/**
 * For native screens with protected content. Their routes need the app-lock middleware too, which
 * stops them mounting while locked; this trait covers the moments middleware does not run:
 *
 * - a shielded return from the background while the screen is showing, and
 * - the screen being shown again after the one above it closed (onResume).
 *
 * In both cases a locked app replaces the screen with the lock route. The shield stays up until the
 * lock screen releases it. Put your own resume work in resumed(), not onResume().
 */
trait RequiresUnlock
{
    #[On(Foregrounded::class)]
    public function lockOnShieldedReturn(int $secondsAway, bool $shielded): void
    {
        if ($shielded) {
            $this->leaveIfLocked();
        }
    }

    public function onResume(): void
    {
        if (! $this->leaveIfLocked()) {
            $this->resumed();
        }
    }

    /**
     * Runs when the screen is shown again unlocked.
     */
    protected function resumed(): void {}

    /**
     * Replaces this screen with the lock route when the app is locked. True when it did.
     */
    protected function leaveIfLocked(): bool
    {
        $lock = app(AppLock::class);
        $lock->boot();

        if (! $lock->isLocked()) {
            return false;
        }

        $lock->rememberIntended((string) $this->nativeRouter?->currentUri());
        $this->replace($this->route((string) config('app-guard.lock.routes.lock', 'lock')));

        return true;
    }
}
