<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\HookContract;

/**
 * Gathers the plugins' handlers for the activities other servers send,
 * asked each time one arrives whose type Teme does not take itself.
 *
 * @since 1.0.0
 */
#[HookContract(capability: 'fediverse.read')]
final readonly class CollectActivityHandlers implements Collect
{
    /** @since 1.0.0 */
    public static function itemType(): string
    {
        return ActivityHandler::class;
    }
}
