<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard;

use FirstlightUI\AppGuard\Exceptions\LockStorageException;
use InvalidArgumentException;
use LogicException;
use Native\Mobile\Facades\SecureStorage;
use SensitiveParameter;

/**
 * An optional app PIN. Only its password_hash() is stored, in SecureStorage, with the count of wrong
 * PINs beside it so the budget survives restarts. The PIN itself is never stored or logged.
 *
 * Every read tells "nothing stored" apart from "could not read": a locked device, a missing secure
 * storage plugin or a failed bridge call throws LockStorageException, never reads as "no PIN".
 */
final class Pin
{
    public const string HASH_KEY = 'app_guard.pin_hash';

    public const string ATTEMPTS_KEY = 'app_guard.pin_failed_attempts';

    public const string BIOMETRICS_KEY = 'app_guard.biometrics_enabled';

    /** The stored hash once read, false when read as absent, null when not read yet. */
    private string|false|null $hash = null;

    public function exists(): bool
    {
        return $this->hash() !== null;
    }

    /**
     * Whether $pin is digits only and of the configured length. set() and change() enforce this.
     */
    public function valid(#[SensitiveParameter] string $pin): bool
    {
        return ctype_digit($pin) && strlen($pin) >= $this->minLength() && strlen($pin) <= $this->maxLength();
    }

    /**
     * Stores the first PIN and starts a fresh attempt budget. Replacing an existing PIN goes through
     * change(), which needs the current one.
     *
     * @throws InvalidArgumentException when the PIN is not digits of the configured length
     * @throws LogicException when a PIN already exists
     */
    public function set(#[SensitiveParameter] string $pin): void
    {
        $this->assertValid($pin);

        if ($this->exists()) {
            throw new LogicException('A PIN already exists. Change it with the current PIN, or clear it first.');
        }

        $this->store($pin);
    }

    /**
     * Checks a PIN. A wrong PIN uses one attempt from the budget; once it is spent no PIN is accepted,
     * the right one included, until clear(). False when no PIN exists.
     */
    public function verify(#[SensitiveParameter] string $pin): bool
    {
        $hash = $this->hash();

        if ($hash === null) {
            return false;
        }

        $failed = $this->failedAttempts();

        if ($failed >= $this->maxAttempts()) {
            return false;
        }

        // Counted before comparing, so ending the app mid-check cannot get a guess for free.
        $this->write(self::ATTEMPTS_KEY, (string) ($failed + 1));

        if (! password_verify($pin, $hash)) {
            return false;
        }

        $this->forget(self::ATTEMPTS_KEY);

        return true;
    }

    /**
     * Replaces the PIN when $current is right. A wrong $current uses an attempt, as verify() does.
     *
     * @throws InvalidArgumentException when the new PIN is not digits of the configured length
     */
    public function change(#[SensitiveParameter] string $current, #[SensitiveParameter] string $new): bool
    {
        $this->assertValid($new);

        if (! $this->verify($current)) {
            return false;
        }

        $this->store($new);

        return true;
    }

    /**
     * Removes the PIN, its attempt count and the biometrics preference, for example on sign-out.
     */
    public function clear(): void
    {
        $this->hash = null;
        $this->forget(self::HASH_KEY);
        $this->forget(self::ATTEMPTS_KEY);
        $this->forget(self::BIOMETRICS_KEY);
    }

    /**
     * Wrong PINs since the last correct one, counted across restarts. A count that cannot be parsed
     * reads as the whole budget spent.
     */
    public function failedAttempts(): int
    {
        $stored = $this->read(self::ATTEMPTS_KEY);

        if ($stored === null) {
            return 0;
        }

        return ctype_digit($stored) ? (int) $stored : $this->maxAttempts();
    }

    public function remainingAttempts(): int
    {
        return max(0, $this->maxAttempts() - $this->failedAttempts());
    }

    /**
     * True once the wrong-PIN budget is spent. The app decides what follows; signing the person out
     * and calling clear() is the usual answer.
     */
    public function exhausted(): bool
    {
        return $this->failedAttempts() >= $this->maxAttempts();
    }

    public function biometricsEnabled(): bool
    {
        return $this->read(self::BIOMETRICS_KEY) === '1';
    }

    public function setBiometricsEnabled(bool $enabled): void
    {
        $enabled ? $this->write(self::BIOMETRICS_KEY, '1') : $this->forget(self::BIOMETRICS_KEY);
    }

    public function minLength(): int
    {
        return max(1, (int) config('app-guard.pin.min_length', 4));
    }

    public function maxLength(): int
    {
        return max($this->minLength(), (int) config('app-guard.pin.max_length', 6));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('app-guard.pin.max_attempts', 5));
    }

    private function assertValid(#[SensitiveParameter] string $pin): void
    {
        if (! $this->valid($pin)) {
            throw new InvalidArgumentException("A PIN must be {$this->minLength()} to {$this->maxLength()} digits.");
        }
    }

    private function store(#[SensitiveParameter] string $pin): void
    {
        // A new PIN starts a new budget. Reset first, so a new hash never sits beside an old count.
        $this->forget(self::ATTEMPTS_KEY);
        $this->hash = null;

        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $this->write(self::HASH_KEY, $hash);
        $this->hash = $hash;
    }

    /**
     * Only this class writes the hash, so a successful read is kept for the rest of the process.
     * A failed read is not kept: it throws, and the next call asks again.
     */
    private function hash(): ?string
    {
        if ($this->hash === null) {
            $this->hash = $this->read(self::HASH_KEY) ?? false;
        }

        return $this->hash === false ? null : $this->hash;
    }

    private function read(string $key): ?string
    {
        $result = SecureStorage::read($key);

        return match (true) {
            $result->found() => $result->value,
            $result->missing() => null,
            $result->unavailable() => throw LockStorageException::unavailable($key),
            default => throw LockStorageException::unreadable($key, $result->code),
        };
    }

    private function write(string $key, string $value): void
    {
        if (! SecureStorage::set($key, $value)) {
            throw LockStorageException::unwritable($key);
        }
    }

    /**
     * Deleting a key that is not there may report failure natively, so a failed delete counts only
     * when the key can still be read.
     */
    private function forget(string $key): void
    {
        if (! SecureStorage::delete($key) && $this->read($key) !== null) {
            throw LockStorageException::unwritable($key);
        }
    }
}
