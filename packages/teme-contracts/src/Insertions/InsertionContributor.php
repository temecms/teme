<?php

/**
 * Something that adds markup inside published articles at named points.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

/**
 * Something that adds markup inside published articles at named points.
 * Registered under core's insertion registry from a plugin granted
 * content.insert, and asked only for public web pages of items that have
 * not opted out.
 *
 * @since 1.0.0
 */
interface InsertionContributor
{
    /**
     * Whom it speaks for: "plugin", "site" or "core". A plugin's entry
     * wins a shared key, then the site's, then core's.
     *
     * @since 1.0.0
     */
    public function precedence(): string;

    /**
     * Its insertions for this article; none where it adds nothing.
     *
     * @return list<Insertion>
     *
     * @since 1.0.0
     */
    public function insertions(ArticleFacts $article): array;
}
