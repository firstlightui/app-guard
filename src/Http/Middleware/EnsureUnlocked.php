<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Http\Middleware;

use Closure;
use FirstlightUI\AppGuard\AppLock;
use FirstlightUI\AppGuard\Exceptions\LockStorageException;
use FirstlightUI\AppGuard\Pin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app-lock route middleware. While the lock is on, it sends the app to the setup route when
 * app-guard.lock.require_pin is on and no PIN exists, to the lock route when the app is locked, and
 * otherwise lets it through.
 *
 * nativephp/mobile 4.7 runs native route middleware before a screen mounts during in-app navigation
 * (ScreenGuard), and the HTTP kernel runs it for the launch screen, so a protected screen never
 * mounts while the app is locked. The lock and setup routes always pass.
 */
final class EnsureUnlocked
{
    public function __construct(
        private readonly AppLock $lock,
        private readonly Pin $pin,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->lock->boot();

        $lockRoute = (string) config('app-guard.lock.routes.lock', 'lock');
        $setupRoute = (string) config('app-guard.lock.routes.setup', 'lock.setup');

        if (! $this->lock->isEnabled() || $request->routeIs($lockRoute, $setupRoute)) {
            return $next($request);
        }

        // Checked unlocked too: enable() keeps the session unlocked, and a required PIN must still be set.
        $destination = match (true) {
            $this->needsSetup() => $setupRoute,
            $this->lock->isLocked() => $lockRoute,
            default => null,
        };

        if ($destination === null) {
            return $next($request);
        }

        $this->lock->rememberIntended($request->getRequestUri());

        return redirect()->route($destination);
    }

    /**
     * Storage that cannot be read is not "no PIN": that goes to the lock screen, never to setup.
     */
    private function needsSetup(): bool
    {
        if (! config('app-guard.lock.require_pin', false)) {
            return false;
        }

        try {
            return ! $this->pin->exists();
        } catch (LockStorageException $e) {
            report($e);

            return false;
        }
    }
}
