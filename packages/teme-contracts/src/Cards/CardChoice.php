<?php

/**
 * One option of a plugin card's choice field: the value stored, the label
 * shown, a hint, and whether it fits the region's size.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

/**
 * One option of a plugin card's choice field: the value stored, at most
 * 191 characters, the label shown, a short hint, and whether it fits the
 * region's size, null when that doesn't apply.
 *
 * @since next
 */
final readonly class CardChoice
{
    /**
     * @since next
     */
    public function __construct(
        public string $value,
        public string $label,
        public ?string $hint = null,
        public ?bool $fits = null,
    ) {}
}
