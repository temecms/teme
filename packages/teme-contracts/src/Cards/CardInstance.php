<?php

/**
 * One placed plugin card as render() is given it: its node's ID, the values
 * of its fields, the region it is in and the page's language.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Cards;

/**
 * One placed plugin card: its node's ID, the values of its fields as
 * stored, the region it is in, and the page's language. Never anything
 * about the visitor: the page is cached whole for everyone.
 *
 * @since 1.5.0
 */
final readonly class CardInstance
{
    /**
     * @param  string  $id  the card's node ID, the same in every revision of the region
     * @param  array<string, mixed>  $values  by field key; a field never filled in is missing
     *
     * @since 1.5.0
     */
    public function __construct(
        public string $id,
        public array $values,
        public CardRegion $region,
        public string $language,
    ) {}
}
