<?php

declare(strict_types=1);

namespace App\Domain\Fediverse;

use App\Domain\Settings\Modules;
use InvalidArgumentException;

/**
 * Whether the fediverse is on: the `modules.fediverse` switch, off by
 * default (I-077). Off means paused (proposal, Settled 4): documents and
 * WebFinger still answer, inboxes answer 503, nothing is queued, and keys
 * and followers are kept. Off, too, until the switch is registered.
 */
final class FediverseSwitch
{
    public const string MODULE = 'fediverse';

    public static function on(): bool
    {
        try {
            return Modules::on(self::MODULE);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
