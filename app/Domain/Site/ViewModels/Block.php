<?php

/**
 * One top-level block of an article's body, as a template reads it from
 * `$article->blocks`: the node's type, its attributes and its HTML.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One top-level block of an article's body: a paragraph, a heading, a list or
 * a card, as the page draws it.
 */
final readonly class Block
{
    /**
     * @param  string  $type  the node's type, such as "paragraph", "heading" or "image"
     * @param  array<string, mixed>  $attrs  the node's attributes: a heading's `level`, an image's media and alt, a card's own, with the defaults it declares
     * @param  string  $html  the block drawn as HTML, by the renderer the body is drawn by
     */
    public function __construct(
        public string $type,
        public array $attrs,
        public string $html,
    ) {}
}
