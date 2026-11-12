<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Tests\Fixtures;

use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;

/**
 * An in-memory keychain behind the SecureStorage bridge functions, answering in the secure storage
 * plugin's wire format. It outlives the services under test, the way the real keychain outlives a process.
 */
final class FakeKeychain
{
    /** @var array<string, string> */
    public array $items = [];

    /**
     * Answer every read with this instead, to simulate a locked device or a failed bridge.
     *
     * @var string|array<string, mixed>|null
     */
    public string|array|null $readResponse = null;

    public bool $failReads = false;

    public bool $writable = true;

    public FakeBridge $bridge;

    public static function install(): self
    {
        $keychain = new self;
        $keychain->bridge = Native::fakeBridge()
            ->respondTo('SecureStorage.Get', fn (array $params) => $keychain->get($params['key']))
            ->respondTo('SecureStorage.Set', fn (array $params) => $keychain->set($params['key'], $params['value']))
            ->respondTo('SecureStorage.Delete', fn (array $params) => $keychain->delete($params['key']));

        return $keychain;
    }

    /**
     * @return string|array<string, mixed>|null
     */
    private function get(string $key): string|array|null
    {
        if ($this->failReads) {
            return $this->readResponse;
        }

        return array_key_exists($key, $this->items)
            ? ['status' => 'found', 'value' => $this->items[$key]]
            : ['status' => 'not_found'];
    }

    /**
     * @return array<string, bool>
     */
    private function set(string $key, ?string $value): array
    {
        if (! $this->writable) {
            return ['success' => false];
        }

        if ($value === null) {
            unset($this->items[$key]);
        } else {
            $this->items[$key] = $value;
        }

        return ['success' => true];
    }

    /**
     * Deleting a key that is not there reports failure, as some keystores do.
     *
     * @return array<string, bool>
     */
    private function delete(string $key): array
    {
        if (! $this->writable || ! array_key_exists($key, $this->items)) {
            return ['success' => false];
        }

        unset($this->items[$key]);

        return ['success' => true];
    }
}
