<?php

/**
 * One option of a plugin card's choice field: the value stored, the label
 * shown, a hint, and whether it fits the region's size.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

/**
 * One option of a plugin card's choice field: the value stored, at most
 * 191 characters, the label shown, a short hint, and whether it fits the
 * region's size, null when that doesn't apply.
 *
 * @since 1.5.0
 */
final readonly class CardChoice
{
    /**
     * @since 1.5.0
     */
    public function __construct(
        public string $value,
        public string $label,
        public ?string $hint = null,
        public ?bool $fits = null,
    ) {}
}
