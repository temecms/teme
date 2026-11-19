<?php

/**
 * Reads effective setting values: the stored override, or the declared
 * default.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Settings;

/**
 * Reads effective setting values: the stored override, or the declared
 * default. Never returns secrets — see SecretReader.
 *
 * @since 0.1.0
 */
interface SettingsReader
{
    /**
     * @return array<string, mixed> every non-secret setting in the group
     *
     * @since 0.1.0
     */
    public function group(string $group): array;

    /** @since 0.1.0 */
    public function get(string $group, string $key): mixed;

    /** @since 0.1.0 */
    public function string(string $group, string $key): string;

    /** @since 0.1.0 */
    public function int(string $group, string $key): int;

    /** @since 0.1.0 */
    public function bool(string $group, string $key): bool;

    /**
     * @return list<string>
     *
     * @since 0.1.0
     */
    public function stringList(string $group, string $key): array;
}
