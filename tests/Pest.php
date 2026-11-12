<?php

use FirstlightUI\AppGuard\AppGuard;
use FirstlightUI\AppGuard\AppLock;
use FirstlightUI\AppGuard\LockSettings;
use FirstlightUI\AppGuard\Pin;
use FirstlightUI\AppGuard\Tests\TestCase;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;

pest()->extend(TestCase::class)->in('Feature');

/**
 * A new PHP process: every in-memory service is rebuilt, while the settings file and the keychain stay.
 */
function restartProcess(): void
{
    foreach ([AppLock::class, Pin::class, LockSettings::class, AppGuard::class] as $service) {
        app()->forgetInstance($service);
    }
}

function fakeGuardBridge(): FakeBridge
{
    return Native::fakeBridge()
        ->respondTo('AppGuard.SetPrivacyCover', [])
        ->respondTo('AppGuard.SetShieldTimeout', [])
        ->respondTo('AppGuard.ReleaseShield', []);
}

/**
 * @return list<int|null>
 */
function shieldTimeouts(FakeBridge $bridge): array
{
    return array_map(fn (array $call) => $call['params']['seconds'], $bridge->callsTo('AppGuard.SetShieldTimeout'));
}
