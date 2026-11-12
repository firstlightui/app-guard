<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard;

use FirstlightUI\AppGuard\Exceptions\LockStorageException;

/**
 * The default LockSettings: the person's choices in a small JSON file, the config values until they choose.
 *
 * A missing or unreadable file reads as the config defaults, so a fresh install locks if the config says so.
 */
final class FileLockSettings implements LockSettings
{
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function __construct(
        private readonly string $path,
        private readonly bool $defaultEnabled,
        private readonly int $defaultTimeout,
        private readonly bool $defaultPrivacyCover,
    ) {}

    public function enabled(): bool
    {
        $value = $this->stored()['enabled'] ?? null;

        return is_bool($value) ? $value : $this->defaultEnabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->put('enabled', $enabled);
    }

    public function timeout(): int
    {
        $value = $this->stored()['timeout'] ?? null;

        return is_int($value) && $value >= 0 ? $value : $this->defaultTimeout;
    }

    public function setTimeout(int $seconds): void
    {
        $this->put('timeout', $seconds);
    }

    public function privacyCover(): bool
    {
        $value = $this->stored()['privacy_cover'] ?? null;

        return is_bool($value) ? $value : $this->defaultPrivacyCover;
    }

    public function setPrivacyCover(bool $enabled): void
    {
        $this->put('privacy_cover', $enabled);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        $contents = is_file($this->path) ? @file_get_contents($this->path) : false;
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        return $this->stored = is_array($decoded) ? $decoded : [];
    }

    private function put(string $key, bool|int $value): void
    {
        $stored = [...$this->stored(), $key => $value];
        $directory = dirname($this->path);
        $temporary = $this->path.'.tmp';

        // Written beside the file, then renamed over it, so a crash never leaves half a file.
        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))
            || @file_put_contents($temporary, json_encode($stored, JSON_THROW_ON_ERROR)) === false
            || ! @rename($temporary, $this->path)) {
            throw LockStorageException::settingsUnwritable($this->path);
        }

        $this->stored = $stored;
    }
}
