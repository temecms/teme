<?php

/**
 * One change to a settings group, as SettingsWriter applies it: the values to
 * set, the keys to reset, any secrets, and who asked and from where.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings;

final readonly class SettingsWrite
{
    /**
     * @param  array<string, mixed>  $values  key => new value
     * @param  list<string>  $reset  keys to return to their default
     * @param  array<string, string|null>  $secrets  key => new secret, '' keeps it, null clears it
     * @param  int|null  $expectedVersion  the version the form was opened on; null skips the check
     */
    public function __construct(
        public string $group,
        public array $values,
        public array $reset = [],
        public array $secrets = [],
        public ?int $expectedVersion = null,
        public ?string $actorId = null,
        public string $origin = 'human',
    ) {}
}
