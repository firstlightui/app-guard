<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool setPrivacyCover(bool $enabled)
 * @method static bool shieldAfter(?int $seconds)
 * @method static bool releaseShield()
 * @method static bool copySensitive(string $text, int $expiresInSeconds = 60)
 *
 * @see \FirstlightUI\AppGuard\AppGuard
 */
final class AppGuard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \FirstlightUI\AppGuard\AppGuard::class;
    }
}
