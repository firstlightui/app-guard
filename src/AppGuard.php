<?php

declare(strict_types=1);

namespace WojtJanowski\AppGuard;

use InvalidArgumentException;

/**
 * PHP side of the app-guard native plugin.
 *
 * Every method returns whether the native side applied the change. False means the bridge
 * function is missing (no native build, or the plugin is not registered) or reported an error.
 */
final class AppGuard
{
    public const int MAX_CLIPBOARD_SECONDS = 3600;

    /**
     * Hide app content in the app switcher: a blur on iOS, FLAG_SECURE on Android (which also blocks screenshots).
     */
    public function setPrivacyCover(bool $enabled): bool
    {
        return $this->call('AppGuard.SetPrivacyCover', ['enabled' => $enabled]);
    }

    /**
     * Cover the app with an opaque shield when it returns after at least $seconds in the background.
     * The shield stays up until releaseShield(). Null turns the shield off; 0 shields every return.
     */
    public function shieldAfter(?int $seconds): bool
    {
        if ($seconds !== null && $seconds < 0) {
            throw new InvalidArgumentException('The shield timeout cannot be negative.');
        }

        return $this->call('AppGuard.SetShieldTimeout', ['seconds' => $seconds]);
    }

    /**
     * Fade out and remove the return shield.
     */
    public function releaseShield(): bool
    {
        return $this->call('AppGuard.ReleaseShield');
    }

    /**
     * Copy text that must not sync to other devices and should leave the clipboard after $expiresInSeconds.
     */
    public function copySensitive(string $text, int $expiresInSeconds = 60): bool
    {
        if ($text === '') {
            throw new InvalidArgumentException('There is nothing to copy.');
        }

        if ($expiresInSeconds < 1 || $expiresInSeconds > self::MAX_CLIPBOARD_SECONDS) {
            throw new InvalidArgumentException('Clipboard expiry must be between 1 and '.self::MAX_CLIPBOARD_SECONDS.' seconds.');
        }

        return $this->call('AppGuard.CopySensitive', ['text' => $text, 'expiresIn' => $expiresInSeconds]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function call(string $method, array $parameters = []): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $response = nativephp_call($method, json_encode((object) $parameters, JSON_THROW_ON_ERROR));

        if ($response === null) {
            return false;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) && ($decoded['status'] ?? null) !== 'error';
    }
}
