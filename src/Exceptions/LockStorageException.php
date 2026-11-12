<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Exceptions;

use RuntimeException;

/**
 * Lock state could not be read or written. It never means "nothing is stored": a locked device,
 * a missing secure storage plugin or a failed bridge call all land here, so callers must not take
 * it as "no PIN".
 */
final class LockStorageException extends RuntimeException
{
    public static function unavailable(string $key): self
    {
        return new self("Secure storage cannot decrypt [{$key}] while the device is locked.");
    }

    public static function unreadable(string $key, ?string $code): self
    {
        return new self("Secure storage could not read [{$key}]".($code === null ? '.' : " ({$code})."));
    }

    public static function unwritable(string $key): self
    {
        return new self("Secure storage could not write [{$key}].");
    }

    public static function settingsUnwritable(string $path): self
    {
        return new self("Lock settings could not be written to [{$path}].");
    }
}
