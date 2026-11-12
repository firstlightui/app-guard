<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard;

/**
 * Where AppLock keeps the person's lock choices.
 *
 * The default, FileLockSettings, is a JSON file. Bind your own implementation to keep them in
 * your app's settings store instead. enabled() answers whether the lock applies right now, so an
 * app that locks only while someone is signed in folds that check into it.
 */
interface LockSettings
{
    public function enabled(): bool;

    public function setEnabled(bool $enabled): void;

    /**
     * Seconds in the background before a return needs the lock screen. 0 locks on every return.
     */
    public function timeout(): int;

    public function setTimeout(int $seconds): void;

    public function privacyCover(): bool;

    public function setPrivacyCover(bool $enabled): void;
}
