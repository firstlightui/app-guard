<?php

use FirstlightUI\AppGuard\Exceptions\LockStorageException;
use FirstlightUI\AppGuard\Pin;
use FirstlightUI\AppGuard\Tests\Fixtures\FakeKeychain;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->keychain = FakeKeychain::install();
});

it('accepts digits of the configured length only', function (string $pin, bool $valid) {
    config()->set('app-guard.pin.min_length', 4);
    config()->set('app-guard.pin.max_length', 6);

    expect(app(Pin::class)->valid($pin))->toBe($valid);
})->with([
    'four digits' => ['1234', true],
    'six digits' => ['123456', true],
    'leading zeros' => ['0000', true],
    'three digits' => ['123', false],
    'seven digits' => ['1234567', false],
    'empty' => ['', false],
    'letters' => ['12a4', false],
    'spaces' => ['12 34', false],
    'negative' => ['-1234', false],
    'decimal' => ['12.34', false],
    'non-ASCII digits' => ['١٢٣٤', false],
]);

it('follows the configured length', function () {
    config()->set('app-guard.pin.min_length', 6);
    config()->set('app-guard.pin.max_length', 8);

    expect(app(Pin::class)->valid('12345'))->toBeFalse()
        ->and(app(Pin::class)->valid('12345678'))->toBeTrue()
        ->and(app(Pin::class)->valid('123456789'))->toBeFalse();
});

it('refuses an invalid PIN without writing anything', function () {
    expect(fn () => app(Pin::class)->set('12a4'))->toThrow(InvalidArgumentException::class);

    expect($this->keychain->items)->toBe([])
        ->and($this->keychain->bridge->callsTo('SecureStorage.Set'))->toBe([]);
});

it('stores only a password hash of the PIN, in secure storage', function () {
    app(Pin::class)->set('482916');

    $hash = $this->keychain->items[Pin::HASH_KEY];

    expect($hash)->not->toContain('482916')
        ->and(password_get_info($hash)['algo'])->not->toBeNull()
        ->and(password_verify('482916', $hash))->toBeTrue()
        ->and(json_encode($this->keychain->bridge->calls))->not->toContain('482916');
});

it('never writes the PIN to the log', function () {
    Log::spy();
    $pin = app(Pin::class);

    $pin->set('482916');
    $pin->verify('111111');
    $pin->verify('482916');

    Log::shouldNotHaveReceived('log');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('verifies the right PIN and rejects a wrong one', function () {
    $pin = app(Pin::class);
    $pin->set('2468');

    expect($pin->verify('2468'))->toBeTrue()
        ->and($pin->verify('2469'))->toBeFalse()
        ->and($pin->verify('246'))->toBeFalse()
        ->and($pin->verify(''))->toBeFalse();
});

it('verifies against what is stored, not against a copy held in memory', function () {
    app(Pin::class)->set('2468');

    restartProcess();

    expect(app(Pin::class)->exists())->toBeTrue()
        ->and(app(Pin::class)->verify('2468'))->toBeTrue();
});

it('rejects every PIN when none exists', function () {
    $pin = app(Pin::class);

    expect($pin->exists())->toBeFalse()
        ->and($pin->verify('1234'))->toBeFalse()
        ->and($pin->failedAttempts())->toBe(0);
});

it('will not set a second PIN over the first', function () {
    $pin = app(Pin::class);
    $pin->set('1234');

    expect(fn () => $pin->set('5678'))->toThrow(LogicException::class);

    restartProcess();
    expect(fn () => app(Pin::class)->set('5678'))->toThrow(LogicException::class);
    expect(app(Pin::class)->verify('1234'))->toBeTrue();
});

it('changes the PIN only with the current one', function () {
    $pin = app(Pin::class);
    $pin->set('1234');

    expect($pin->change('9999', '5678'))->toBeFalse()
        ->and($pin->failedAttempts())->toBe(1)
        ->and($pin->change('1234', '5678'))->toBeTrue()
        ->and($pin->failedAttempts())->toBe(0);

    restartProcess();

    expect(app(Pin::class)->verify('1234'))->toBeFalse()
        ->and(app(Pin::class)->verify('5678'))->toBeTrue();
});

it('refuses an invalid new PIN before checking the current one', function () {
    $pin = app(Pin::class);
    $pin->set('1234');

    expect(fn () => $pin->change('1234', '12'))->toThrow(InvalidArgumentException::class);

    expect($pin->failedAttempts())->toBe(0)
        ->and($pin->verify('1234'))->toBeTrue();
});

it('clears the PIN, its attempt count and the biometrics preference', function () {
    $pin = app(Pin::class);
    $pin->set('1234');
    $pin->setBiometricsEnabled(true);
    $pin->verify('0000');

    $pin->clear();

    expect($this->keychain->items)->toBe([])
        ->and($pin->exists())->toBeFalse()
        ->and($pin->biometricsEnabled())->toBeFalse();

    $pin->set('5678');
    expect($pin->failedAttempts())->toBe(0);
});

it('counts wrong PINs across restarts and resets the count on the right one', function () {
    config()->set('app-guard.pin.max_attempts', 5);
    app(Pin::class)->set('1234');

    app(Pin::class)->verify('0000');
    restartProcess();
    app(Pin::class)->verify('0000');
    restartProcess();

    expect(app(Pin::class)->failedAttempts())->toBe(2)
        ->and(app(Pin::class)->remainingAttempts())->toBe(3)
        ->and(app(Pin::class)->verify('1234'))->toBeTrue()
        ->and(app(Pin::class)->failedAttempts())->toBe(0);
});

it('accepts no PIN, the right one included, once the budget is spent, even after a restart', function () {
    config()->set('app-guard.pin.max_attempts', 3);
    app(Pin::class)->set('1234');

    foreach (['0000', '1111'] as $wrong) {
        app(Pin::class)->verify($wrong);
        restartProcess();
    }

    expect(app(Pin::class)->exhausted())->toBeFalse();

    app(Pin::class)->verify('2222');
    restartProcess();

    expect(app(Pin::class)->exhausted())->toBeTrue()
        ->and(app(Pin::class)->remainingAttempts())->toBe(0)
        ->and(app(Pin::class)->verify('1234'))->toBeFalse()
        ->and(app(Pin::class)->change('1234', '5678'))->toBeFalse()
        ->and(app(Pin::class)->failedAttempts())->toBe(3);
});

it('starts a fresh budget once an exhausted PIN is cleared and set again', function () {
    config()->set('app-guard.pin.max_attempts', 1);
    $pin = app(Pin::class);
    $pin->set('1234');
    $pin->verify('0000');
    expect($pin->exhausted())->toBeTrue();

    $pin->clear();
    $pin->set('1234');

    expect($pin->exhausted())->toBeFalse()
        ->and($pin->verify('1234'))->toBeTrue();
});

it('reads a tampered attempt count as a spent budget', function () {
    app(Pin::class)->set('1234');
    $this->keychain->items[Pin::ATTEMPTS_KEY] = 'oops';

    expect(app(Pin::class)->exhausted())->toBeTrue()
        ->and(app(Pin::class)->verify('1234'))->toBeFalse();
});

it('keeps the biometrics preference across restarts', function () {
    app(Pin::class)->setBiometricsEnabled(true);
    restartProcess();
    expect(app(Pin::class)->biometricsEnabled())->toBeTrue();

    app(Pin::class)->setBiometricsEnabled(false);
    restartProcess();
    expect(app(Pin::class)->biometricsEnabled())->toBeFalse();
});

it('treats a locked device as an error, not as no PIN', function () {
    $this->keychain->items[Pin::HASH_KEY] = password_hash('1234', PASSWORD_DEFAULT);
    $this->keychain->failReads = true;
    $this->keychain->readResponse = ['status' => 'unavailable'];

    expect(fn () => app(Pin::class)->exists())->toThrow(LockStorageException::class)
        ->and(fn () => app(Pin::class)->verify('1234'))->toThrow(LockStorageException::class)
        ->and(fn () => app(Pin::class)->set('5678'))->toThrow(LockStorageException::class);

    expect(password_verify('1234', $this->keychain->items[Pin::HASH_KEY]))->toBeTrue();
});

it('treats a failed or missing bridge as an error, not as no PIN', function (string|array|null $response) {
    $this->keychain->failReads = true;
    $this->keychain->readResponse = $response;

    expect(fn () => app(Pin::class)->exists())->toThrow(LockStorageException::class)
        ->and(fn () => app(Pin::class)->failedAttempts())->toThrow(LockStorageException::class)
        ->and(fn () => app(Pin::class)->biometricsEnabled())->toThrow(LockStorageException::class);
})->with([
    'bridge error' => [['status' => 'error', 'code' => 'EXECUTION_FAILED']],
    'plugin missing' => [null],
    'garbage' => ['not json'],
]);

it('does not count a guess it could not record', function () {
    app(Pin::class)->set('1234');
    $this->keychain->writable = false;

    expect(fn () => app(Pin::class)->verify('0000'))->toThrow(LockStorageException::class)
        ->and(fn () => app(Pin::class)->verify('1234'))->toThrow(LockStorageException::class);
});

it('reports a PIN it could not store', function () {
    $this->keychain->writable = false;

    expect(fn () => app(Pin::class)->set('1234'))->toThrow(LockStorageException::class);

    $this->keychain->writable = true;
    expect(app(Pin::class)->exists())->toBeFalse();
});
