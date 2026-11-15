<?php

declare(strict_types=1);

namespace App\Domain\Settings\Events;

/**
 * A settings group changed, after commit: which group, the keys whose values
 * changed, who changed them (null for the operator or the system), and the
 * language whose words changed (null for the site's own values, I-3360).
 * The group's cache is already forgotten, so a listener reads the new values.
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
        public ?string $language = null,
    ) {}
}
