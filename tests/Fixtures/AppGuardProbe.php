<?php

declare(strict_types=1);

namespace WojtJanowski\AppGuard\Tests\Fixtures;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use WojtJanowski\AppGuard\Events\Backgrounded;
use WojtJanowski\AppGuard\Events\Foregrounded;

/**
 * A minimal screen that listens to app-guard events the way Plan C's screens will.
 */
final class AppGuardProbe extends NativeComponent
{
    public int $backgroundedCount = 0;

    public int $secondsAway = -1;

    public bool $shielded = false;

    #[On(Backgrounded::class)]
    public function onBackgrounded(): void
    {
        $this->backgroundedCount++;
    }

    #[On(Foregrounded::class)]
    public function onForegrounded(int $secondsAway, bool $shielded): void
    {
        $this->secondsAway = $secondsAway;
        $this->shielded = $shielded;
    }

    public function render(): View
    {
        return view('app-guard-probe');
    }
}
