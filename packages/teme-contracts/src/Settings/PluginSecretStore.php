<?php

/**
 * Keeps a secret a plugin receives while it runs, such as a token from a
 * connect flow, in the plugin's own encrypted store.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Settings;

use Teme\Contracts\Content\WriteRefused;

/**
 * Keeps up to 100 free-named secrets of up to 4 KB each, encrypted at rest in
 * the plugin's settings group, for the person its work runs for. Only a plugin
 * that declares a secret setting is given it. A key is a lower-case name of 2
 * to 64 characters, starting with a letter, with letters, digits and
 * underscores; a value is never shown back to a person, only read by the plugin.
 *
 * @since next
 */
interface PluginSecretStore
{
    /**
     * The value kept under the key, or null when none is kept.
     *
     * @since next
     */
    public function get(string $key): ?string;

    /**
     * Keeps the value under the key, replacing any value already there.
     *
     * @throws WriteRefused when the key, the value or the count of keys is refused
     *
     * @since next
     */
    public function put(string $key, string $value, string $actingPersonId): void;

    /**
     * Removes the key and its value; a key not kept is a no-op.
     *
     * @throws WriteRefused when the person may not change the plugin's secrets
     *
     * @since next
     */
    public function forget(string $key, string $actingPersonId): void;
}
