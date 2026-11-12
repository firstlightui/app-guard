<?php

use FirstlightUI\AppGuard\AppLock;
use FirstlightUI\AppGuard\Events\Foregrounded;
use FirstlightUI\AppGuard\Tests\Fixtures\ProtectedScreen;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->bridge = fakeGuardBridge();
    app(AppLock::class)->boot();
    app(AppLock::class)->unlock();
    $this->bridge->calls = [];
});

it('replaces a protected screen with the lock screen on a shielded return, leaving the shield up', function () {
    Native::visit('/vault')
        ->emitNative(Foregrounded::class, ['secondsAway' => 90, 'shielded' => true])
        ->assertReplacedWith('/lock')
        ->assertNativeNotCalled('AppGuard.ReleaseShield');

    expect(app(AppLock::class)->isLocked())->toBeTrue()
        ->and(app(AppLock::class)->pullIntended())->toBe('/vault');
});

it('stays on a protected screen after an unshielded return', function () {
    Native::visit('/vault')
        ->emitNative(Foregrounded::class, ['secondsAway' => 5, 'shielded' => false])
        ->assertNoNavigation()
        ->assertNativeNotCalled('AppGuard.ReleaseShield');

    expect(app(AppLock::class)->isLocked())->toBeFalse();
});

it('releases the shield over a screen without protected content, and locks protected screens shown again', function () {
    $vault = Native::visit('/vault');

    Native::visit('/settings')
        ->emitNative(Foregrounded::class, ['secondsAway' => 90, 'shielded' => true])
        ->assertNoNavigation()
        ->assertNativeCalledTimes('AppGuard.ReleaseShield', 1);

    expect(app(AppLock::class)->isLocked())->toBeTrue();

    $vault->call('onResume')->assertReplacedWith('/lock');
    expect($vault->get('resumedCount'))->toBe(0);
});

it('releases the shield and stays put when the lock is off', function () {
    app(AppLock::class)->disable();
    $this->bridge->calls = [];

    Native::test(ProtectedScreen::class)
        ->emitNative(Foregrounded::class, ['secondsAway' => 90, 'shielded' => true])
        ->assertNoNavigation()
        ->assertNativeCalledTimes('AppGuard.ReleaseShield', 1);
});

it('runs the screen\'s own resume work only while unlocked', function () {
    Native::visit('/vault')
        ->call('onResume')
        ->assertNoNavigation()
        ->assertSet('resumedCount', 1);
});

it('leaves the shield alone until AppLock has booted, so apps driving it themselves keep control', function () {
    restartProcess();

    Native::visit('/settings')->emitNative(Foregrounded::class, ['secondsAway' => 90, 'shielded' => true]);

    $this->bridge->assertNotCalled('AppGuard.ReleaseShield');
    expect(app(AppLock::class)->booted())->toBeFalse();
});
