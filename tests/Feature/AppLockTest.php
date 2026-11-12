<?php

use FirstlightUI\AppGuard\AppLock;
use FirstlightUI\AppGuard\LockSettings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->bridge = fakeGuardBridge();
});

it('starts every process locked, even after unlocking in an earlier one', function () {
    $lock = app(AppLock::class);
    expect($lock->isLocked())->toBeTrue();

    $lock->unlock();
    expect($lock->isLocked())->toBeFalse()
        ->and(app(AppLock::class)->isLocked())->toBeFalse();

    restartProcess();

    expect(app(AppLock::class)->isLocked())->toBeTrue();
});

it('locks again after unlocking', function () {
    $lock = app(AppLock::class);
    $lock->unlock();
    $lock->lock();

    expect($lock->isLocked())->toBeTrue();
});

it('is never locked while the lock is off', function () {
    config()->set('app-guard.lock.enabled', false);

    expect(app(AppLock::class)->isLocked())->toBeFalse()
        ->and(app(AppLock::class)->isEnabled())->toBeFalse();
});

it('applies the privacy cover and shield once per process', function () {
    config()->set('app-guard.lock.timeout', 300);

    app(AppLock::class)->boot();
    app(AppLock::class)->boot();

    expect(array_column($this->bridge->callsTo('AppGuard.SetPrivacyCover'), 'params'))->toBe([['enabled' => true]])
        ->and(shieldTimeouts($this->bridge))->toBe([300]);

    restartProcess();
    app(AppLock::class)->boot();

    expect($this->bridge->callsTo('AppGuard.SetPrivacyCover'))->toHaveCount(2)
        ->and(shieldTimeouts($this->bridge))->toBe([300, 300]);
});

it('applies the configured shield image once per process', function () {
    $public = sys_get_temp_dir().'/app-guard-public/'.bin2hex(random_bytes(6));
    mkdir($public, 0777, true);
    file_put_contents($public.'/logo.png', 'logo');
    app()->usePublicPath($public);
    config()->set('app-guard.shield', ['image' => 'logo.png', 'image_dark' => null, 'fit' => 'center']);

    app(AppLock::class)->boot();
    app(AppLock::class)->boot();

    expect(array_column($this->bridge->callsTo('AppGuard.SetShieldImage'), 'params'))
        ->toBe([['image' => $public.'/logo.png', 'imageDark' => null, 'fit' => 'center']]);

    restartProcess();
    app(AppLock::class)->boot();

    expect($this->bridge->callsTo('AppGuard.SetShieldImage'))->toHaveCount(2);

    File::deleteDirectory(dirname($public));
});

it('still arms the shield when the configured shield image is wrong', function () {
    config()->set('app-guard.lock.timeout', 300);
    config()->set('app-guard.shield', ['image' => '/var/www/public/cover.png', 'image_dark' => null, 'fit' => 'fill']);
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (InvalidArgumentException $e) use (&$reported) {
        $reported[] = $e->getMessage();
    });

    app(AppLock::class)->boot();

    expect(shieldTimeouts($this->bridge))->toBe([300])
        ->and($this->bridge->callsTo('AppGuard.SetShieldImage'))->toBe([])
        ->and($reported)->toHaveCount(1);
});

it('boots with the shield off and no cover when the person turned them off', function () {
    $lock = app(AppLock::class);
    $lock->disable();
    $lock->setPrivacyCover(false);

    restartProcess();
    $this->bridge->calls = [];
    app(AppLock::class)->boot();

    expect(array_column($this->bridge->callsTo('AppGuard.SetPrivacyCover'), 'params'))->toBe([['enabled' => false]])
        ->and(shieldTimeouts($this->bridge))->toBe([null]);
});

it('locks on a shielded return and releases the shield when no protected screen is showing', function () {
    $lock = app(AppLock::class);
    $lock->unlock();

    expect($lock->foregrounded(true))->toBeTrue()
        ->and($lock->isLocked())->toBeTrue();
    $this->bridge->assertCalledTimes('AppGuard.ReleaseShield', 1);
});

it('neither locks nor touches the shield on an unshielded return', function () {
    $lock = app(AppLock::class);
    $lock->unlock();

    expect($lock->foregrounded(false))->toBeFalse()
        ->and($lock->isLocked())->toBeFalse();
    $this->bridge->assertNothingCalled();
});

it('releases the shield without locking while the lock is off', function () {
    $lock = app(AppLock::class);
    $lock->disable();

    expect($lock->foregrounded(true))->toBeFalse()
        ->and($lock->isLocked())->toBeFalse();
    $this->bridge->assertCalledTimes('AppGuard.ReleaseShield', 1);
});

it('suspends the shield around the app\'s own prompt and restores the timeout after', function () {
    config()->set('app-guard.lock.timeout', 45);
    $lock = app(AppLock::class);

    $lock->suspendShield();
    $lock->resumeShield();

    expect(shieldTimeouts($this->bridge))->toBe([null, 45]);
});

it('keeps the shield off when resuming while the lock is off', function () {
    $lock = app(AppLock::class);
    $lock->disable();
    $this->bridge->calls = [];

    $lock->suspendShield();
    $lock->resumeShield();

    expect(shieldTimeouts($this->bridge))->toBe([null, null]);
});

it('keeps the person\'s choices across restarts and applies them at once', function () {
    $lock = app(AppLock::class);
    $lock->setTimeout(900);
    $lock->setPrivacyCover(false);
    $lock->disable();

    expect(shieldTimeouts($this->bridge))->toBe([900, null]);

    restartProcess();
    $lock = app(AppLock::class);

    expect($lock->timeout())->toBe(900)
        ->and($lock->privacyCover())->toBeFalse()
        ->and($lock->isEnabled())->toBeFalse();
});

it('keeps the session unlocked when the lock is turned on from inside the app', function () {
    config()->set('app-guard.lock.enabled', false);
    $lock = app(AppLock::class);

    $lock->enable();

    expect($lock->isEnabled())->toBeTrue()
        ->and($lock->isLocked())->toBeFalse()
        ->and(shieldTimeouts($this->bridge))->toBe([60]);

    restartProcess();

    expect(app(AppLock::class)->isLocked())->toBeTrue();
});

it('rejects a negative timeout without storing or applying it', function () {
    expect(fn () => app(AppLock::class)->setTimeout(-1))->toThrow(InvalidArgumentException::class);

    expect(app(AppLock::class)->timeout())->toBe(60);
    $this->bridge->assertNothingCalled();
});

it('reads the app\'s own LockSettings when one is bound', function () {
    app()->instance(LockSettings::class, new class implements LockSettings
    {
        public function enabled(): bool
        {
            return false;
        }

        public function setEnabled(bool $enabled): void {}

        public function timeout(): int
        {
            return 15;
        }

        public function setTimeout(int $seconds): void {}

        public function privacyCover(): bool
        {
            return true;
        }

        public function setPrivacyCover(bool $enabled): void {}
    });
    app()->forgetInstance(AppLock::class);

    expect(app(AppLock::class)->isLocked())->toBeFalse();
});

it('hands the remembered screen to the lock screen once', function () {
    $lock = app(AppLock::class);
    $lock->rememberIntended('/vault/7');

    expect($lock->pullIntended('/home'))->toBe('/vault/7')
        ->and($lock->pullIntended('/home'))->toBe('/home');
});
