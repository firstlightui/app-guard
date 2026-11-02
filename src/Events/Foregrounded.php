<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The app returned from the background. Reaches the active screen's #[On] listeners and app-wide listeners.
 *
 * $secondsAway is measured natively on a clock that keeps running while the device sleeps.
 * $shielded is true while the native shield covers the app; whoever handles it must call
 * AppGuard::releaseShield() once the app is safe to show.
 */
final class Foregrounded implements BroadcastsGlobally
{
    use Dispatchable;

    public function __construct(
        public int $secondsAway,
        public bool $shielded,
    ) {}
}
