<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Transform;

/**
 * Lets plugins change an item's object before it is answered or sent,
 * such as adding a tag. Teme puts back the keys that say what it is and
 * who sent it to whom (`id`, `type`, `attributedTo`, `to`, `cc`) as it
 * wrote them.
 *
 * @since 0.9.0
 */
#[HookContract(capability: 'fediverse.read')]
final readonly class ObjectBuilt implements Transform
{
    /**
     * @param  array<string, mixed>  $object  the object as Teme built it
     * @param  ItemRef  $item  the item it is built from
     *
     * @since 0.9.0
     */
    public function __construct(
        public array $object,
        public ItemRef $item,
    ) {}

    /** @since 0.9.0 */
    public static function valueType(): string
    {
        return 'array';
    }

    /** @since 0.9.0 */
    public function value(): mixed
    {
        return $this->object;
    }
}
