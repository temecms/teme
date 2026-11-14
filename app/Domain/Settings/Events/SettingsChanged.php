<?php

declare(strict_types=1);

namespace App\Domain\Settings\Events;

/**
 * A settings group changed, after commit: which group, the keys whose values
 * changed, and who changed them (null for the operator or the system). The
 * group's cache is already forgotten, so a listener reads the new values.
 */
final readonly class SettingsChanged
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public string $group,
        public array $keys,
        public ?string $actorId,
    ) {}
}
