<?php

declare(strict_types=1);

namespace WojtJanowski\AppGuard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The app moved to the background. Reaches the active screen's #[On] listeners and app-wide listeners.
 *
 * PHP may not run again until the app returns, so do not rely on handling this event promptly.
 */
final class Backgrounded implements BroadcastsGlobally
{
    use Dispatchable;
}
