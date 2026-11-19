<?php

/**
 * Gathers the plugins' object builders, asked each time an item's object is
 * built; the first that names the item's type builds it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\HookContract;

/**
 * Gathers the plugins' object builders, asked each time an item's object
 * is built; the first that names the item's type builds it.
 *
 * @since 1.0.0
 */
#[HookContract(capability: 'fediverse.read')]
final readonly class CollectObjectBuilders implements Collect
{
    /** @since 1.0.0 */
    public static function itemType(): string
    {
        return ObjectBuilder::class;
    }
}
