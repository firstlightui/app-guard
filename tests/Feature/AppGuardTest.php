<?php

use FirstlightUI\AppGuard\Facades\AppGuard;
use Illuminate\Support\Facades\File;
use Native\Mobile\Testing\Native;

it('turns the privacy cover on and off natively', function () {
    $bridge = Native::fakeBridge()->respondTo('AppGuard.SetPrivacyCover', []);

    expect(AppGuard::setPrivacyCover(true))->toBeTrue()
        ->and(AppGuard::setPrivacyCover(false))->toBeTrue();

    expect(array_column($bridge->callsTo('AppGuard.SetPrivacyCover'), 'params'))
        ->toBe([['enabled' => true], ['enabled' => false]]);
});

it('reports failure when the native side returns an error', function () {
    Native::fakeBridge()->respondTo('AppGuard.SetPrivacyCover', [
        'status' => 'error',
        'code' => 'EXECUTION_FAILED',
        'message' => 'No window',
    ]);

    expect(AppGuard::setPrivacyCover(true))->toBeFalse();
});

it('reports failure when the plugin is not compiled into the app', function () {
    Native::fakeBridge();

    expect(AppGuard::releaseShield())->toBeFalse();
});

it('sends the shield timeout, with null turning the shield off', function () {
    $bridge = Native::fakeBridge()->respondTo('AppGuard.SetShieldTimeout', []);

    AppGuard::shieldAfter(300);
    AppGuard::shieldAfter(0);
    AppGuard::shieldAfter(null);

    expect(array_column($bridge->callsTo('AppGuard.SetShieldTimeout'), 'params'))
        ->toBe([['seconds' => 300], ['seconds' => 0], ['seconds' => null]]);
});

it('rejects a negative shield timeout without calling native code', function () {
    $bridge = Native::fakeBridge();

    expect(fn () => AppGuard::shieldAfter(-1))->toThrow(InvalidArgumentException::class);
    $bridge->assertNothingCalled();
});

it('releases the shield natively', function () {
    $bridge = Native::fakeBridge()->respondTo('AppGuard.ReleaseShield', []);

    expect(AppGuard::releaseShield())->toBeTrue();
    $bridge->assertCalledTimes('AppGuard.ReleaseShield', 1);
});

it('copies sensitive text with an expiry', function () {
    $bridge = Native::fakeBridge()->respondTo('AppGuard.CopySensitive', []);

    expect(AppGuard::copySensitive('APP_KEY=base64:abc', 30))->toBeTrue();

    expect($bridge->callsTo('AppGuard.CopySensitive')[0]['params'])
        ->toBe(['text' => 'APP_KEY=base64:abc', 'expiresIn' => 30]);
});

it('accepts clipboard expiries from 1 to 3600 seconds', function (int $seconds) {
    Native::fakeBridge()->respondTo('AppGuard.CopySensitive', []);

    expect(AppGuard::copySensitive('secret', $seconds))->toBeTrue();
})->with([1, 3600]);

it('rejects empty text and clipboard expiries outside 1 to 3600 seconds', function (string $text, int $seconds) {
    $bridge = Native::fakeBridge();

    expect(fn () => AppGuard::copySensitive($text, $seconds))->toThrow(InvalidArgumentException::class);
    $bridge->assertNothingCalled();
})->with([
    'empty text' => ['', 60],
    'zero seconds' => ['secret', 0],
    'over an hour' => ['secret', 3601],
]);

describe('shield image', function () {
    beforeEach(function () {
        $this->public = sys_get_temp_dir().'/app-guard-public/'.bin2hex(random_bytes(6));
        mkdir($this->public.'/brand', 0777, true);
        file_put_contents($this->public.'/brand/cover.png', 'light');
        file_put_contents($this->public.'/brand/cover-dark.png', 'dark');
        app()->usePublicPath($this->public);
        $this->bridge = Native::fakeBridge()->respondTo('AppGuard.SetShieldImage', []);
    });

    afterEach(fn () => File::deleteDirectory(dirname($this->public)));

    it('sends absolute paths under public/ with the fit', function () {
        expect(AppGuard::setShieldImage('brand/cover.png', 'brand/cover-dark.png', 'center'))->toBeTrue();

        expect($this->bridge->callsTo('AppGuard.SetShieldImage')[0]['params'])->toBe([
            'image' => $this->public.'/brand/cover.png',
            'imageDark' => $this->public.'/brand/cover-dark.png',
            'fit' => 'center',
        ]);
    });

    it('sends no image so native falls back to the splash and icon', function () {
        AppGuard::setShieldImage();

        expect($this->bridge->callsTo('AppGuard.SetShieldImage')[0]['params'])
            ->toBe(['image' => null, 'imageDark' => null, 'fit' => 'fill']);
    });

    it('rejects a bad image before calling native code', function (?string $image, ?string $dark, string $fit) {
        expect(fn () => AppGuard::setShieldImage($image, $dark, $fit))->toThrow(InvalidArgumentException::class);
        expect($this->bridge->callsTo('AppGuard.SetShieldImage'))->toBe([]);
    })->with([
        'unknown fit' => ['brand/cover.png', null, 'cover'],
        'missing file' => ['brand/missing.png', null, 'fill'],
        'absolute path' => ['/etc/hosts', null, 'fill'],
        'outside public' => ['../cover.png', null, 'fill'],
        'url' => ['https://example.com/cover.png', null, 'fill'],
        'dark without light' => [null, 'brand/cover-dark.png', 'fill'],
        'missing dark file' => ['brand/cover.png', 'brand/nope.png', 'fill'],
    ]);
});
