<?php

use FirstlightUI\AppGuard\Events\Backgrounded;
use FirstlightUI\AppGuard\Events\Foregrounded;
use FirstlightUI\AppGuard\Tests\Fixtures\AppGuardProbe;
use Illuminate\Support\Facades\Event;
use Native\Mobile\Testing\Native;

it('delivers Foregrounded to the screen and app-wide, coercing native payload types', function () {
    Event::fake([Foregrounded::class]);

    Native::test(AppGuardProbe::class)
        ->emitNative(Foregrounded::class, ['secondsAway' => '301', 'shielded' => true])
        ->assertSet('secondsAway', 301)
        ->assertSet('shielded', true)
        ->assertSee('Away 301');

    Event::assertDispatched(
        Foregrounded::class,
        fn (Foregrounded $event) => $event->secondsAway === 301 && $event->shielded === true,
    );
});

it('delivers Backgrounded to the screen and app-wide', function () {
    Event::fake([Backgrounded::class]);

    Native::test(AppGuardProbe::class)
        ->emitNative(Backgrounded::class)
        ->assertSet('backgroundedCount', 1);

    Event::assertDispatched(Backgrounded::class);
});
