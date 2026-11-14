<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;

/**
 * A group of settings was saved and at least one value changed. Carries the
 * keys that changed, never their values. Sent after the change is saved,
 * never during an import run.
 *
 * @since 0.5.0
 */
#[HookContract]
final readonly class SettingsChanged implements Notification
{
    /**
     * @param  string  $group  the settings group's key
     * @param  list<string>  $keys  the keys written or reset
     * @param  string|null  $actorId  the account ID of the person who saved them, or null when no signed-in person did
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $group,
        public array $keys,
        public ?string $actorId,
    ) {}
}
