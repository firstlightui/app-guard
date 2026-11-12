<?php

use FirstlightUI\AppGuard\AppGuardServiceProvider;
use FirstlightUI\AppGuard\AppLock;
use FirstlightUI\AppGuard\Http\Middleware\EnsureUnlocked;
use FirstlightUI\AppGuard\Pin;
use FirstlightUI\AppGuard\Tests\Fixtures\FakeKeychain;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->keychain = FakeKeychain::install();
    fakeGuardBridge();
});

it('registers the app-lock alias', function () {
    expect(Route::getMiddleware()[AppGuardServiceProvider::MIDDLEWARE] ?? null)->toBe(EnsureUnlocked::class);
});

it('sends a locked app to the lock route before the screen mounts', function () {
    $vault = Native::visit('/vault')->assertReplacedWith('/lock');

    expect($vault->instance()->mounted)->toBe(0)
        ->and(app(AppLock::class)->pullIntended())->toBe('/vault');
});

it('applies the privacy cover and shield on the first guarded navigation', function () {
    Native::visit('/vault')
        ->assertNativeCalled('AppGuard.SetPrivacyCover', fn (array $params) => $params === ['enabled' => true])
        ->assertNativeCalled('AppGuard.SetShieldTimeout', fn (array $params) => $params === ['seconds' => 60]);
});

it('lets an unlocked app through to the screen', function () {
    app(AppLock::class)->unlock();

    $vault = Native::visit('/vault')->assertNoNavigation()->assertSee('Vault');

    expect($vault->instance()->mounted)->toBe(1);
});

it('lets every screen through while the lock is off', function () {
    app(AppLock::class)->disable();
    config()->set('app-guard.lock.require_pin', true);

    Native::visit('/vault')->assertNoNavigation();
});

it('always lets the lock and setup screens open', function (string $uri) {
    config()->set('app-guard.lock.require_pin', true);

    Native::visit($uri)->assertNoNavigation()->assertSee('Plain');
})->with(['/lock', '/lock/setup']);

it('sends the app to PIN setup when a PIN is required and none exists', function () {
    config()->set('app-guard.lock.require_pin', true);

    Native::visit('/vault')->assertReplacedWith('/lock/setup');
});

it('sends an unlocked app to PIN setup when a PIN is required and none exists', function () {
    config()->set('app-guard.lock.require_pin', true);
    app(AppLock::class)->enable();

    Native::visit('/vault')->assertReplacedWith('/lock/setup');
});

it('sends a locked app with a PIN to the lock route, and an unlocked one through', function () {
    config()->set('app-guard.lock.require_pin', true);
    app(Pin::class)->set('1234');

    Native::visit('/vault')->assertReplacedWith('/lock');

    app(AppLock::class)->unlock();
    Native::visit('/vault')->assertNoNavigation();
});

it('does not ask for a PIN unless the config requires one', function () {
    Native::visit('/vault')->assertReplacedWith('/lock');

    $this->keychain->bridge->assertNotCalled('SecureStorage.Get');
});

it('sends the app to the lock screen, never to setup, when secure storage cannot be read', function () {
    config()->set('app-guard.lock.require_pin', true);
    $this->keychain->failReads = true;
    $this->keychain->readResponse = ['status' => 'unavailable'];

    Native::visit('/vault')->assertReplacedWith('/lock');
});

it('redirects a launch request through the HTTP kernel too', function () {
    $this->get('/vault')->assertRedirect('/lock');

    app(AppLock::class)->unlock();
    $this->get('/vault')->assertOk();
});
