<?php

/**
 * Something a document points at by ID (rule 9): a media item or another
 * content item, which cards and links name, or a term or a person, which
 * only links name (I-1702), and whether a link names it rather than a card
 * showing it. The projections are built from these.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

final readonly class CardReference
{
    public function __construct(
        public string $kind,
        public string $id,
        /** Whether a link leads to it, rather than a card showing or naming it. */
        public bool $linked = false,
    ) {}

    public static function media(string $id): self
    {
        return new self('media', $id);
    }

    public static function content(string $id): self
    {
        return new self('content', $id);
    }

    /**
     * What a link by ID leads to: an article, a term, a person or a library
     * file (I-1702).
     */
    public static function linkTo(string $kind, string $id): self
    {
        return new self($kind, $id, true);
    }
}
