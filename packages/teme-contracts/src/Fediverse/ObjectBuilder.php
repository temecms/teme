<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

use Teme\Contracts\Content\ItemRef;

/**
 * A plugin's builder of the object an item of its content types is sent
 * as, such as an Event for an events type. It starts from the object Teme
 * built; Teme keeps the keys that say what it is and who sent it to whom
 * (`id`, `attributedTo`, `to`, `cc`) as it wrote them.
 *
 * @since 0.9.0
 */
interface ObjectBuilder
{
    /**
     * The content types whose objects this builder makes.
     *
     * @return list<string>
     *
     * @since 0.9.0
     */
    public function contentTypes(): array;

    /**
     * The item's object, built from the one Teme made.
     *
     * @param  array<string, mixed>  $object
     *
     * @return array<string, mixed>
     *
     * @since 0.9.0
     */
    public function build(ItemRef $item, array $object): array;
}
