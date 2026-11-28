<?php

/**
 * Keeps a plugin's free secrets in memory for a test, with the key and value
 * rules the real store applies, and no database or encryption.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Testing;

use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Settings\PluginSecretStore;

/**
 * Refuses a key outside the rules, a value that is empty or over 4 KB, and a
 * new key past 100 keys, writing nothing, as the real store does.
 *
 * @since next
 */
final class FakePluginSecretStore implements PluginSecretStore
{
    /** The most keys the store holds. */
    private const int MOST_KEYS = 100;

    /** The longest value, in bytes. */
    private const int MOST_BYTES = 4096;

    /** @var array<string, string> */
    private array $secrets = [];

    /**
     * @since next
     */
    public function get(string $key): ?string
    {
        return $this->secrets[$key] ?? null;
    }

    /**
     * @since next
     */
    public function put(string $key, string $value, string $actingPersonId): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $key) !== 1) {
            throw new WriteRefused('A key is a lower-case name of 2 to 64 characters, starting with a letter.');
        }

        if ($value === '' || strlen($value) > self::MOST_BYTES) {
            throw new WriteRefused('A value is 1 to 4096 bytes.');
        }

        if (! array_key_exists($key, $this->secrets) && count($this->secrets) >= self::MOST_KEYS) {
            throw new WriteRefused('A plugin keeps at most 100 secrets.');
        }

        $this->secrets[$key] = $value;
    }

    /**
     * @since next
     */
    public function forget(string $key, string $actingPersonId): void
    {
        unset($this->secrets[$key]);
    }
}
