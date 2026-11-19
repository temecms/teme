<?php

/**
 * Where an item goes in its type's tree (I-3363): under a parent, or at the
 * top when parentId is null; at a position among its siblings, or, when
 * position is null, where it is if its parent doesn't change and last among
 * its new siblings if it does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

final readonly class TreePlace
{
    public function __construct(
        public ?string $parentId,
        public ?int $position = null,
    ) {}
}
