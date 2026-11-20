<?php

/**
 * What a visitor sent from a plugin's public form, as the plugin hands it to
 * Submissions::submit(): a title, a body and, when given, fields, terms and
 * who sent it.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

/**
 * What a visitor sent from a plugin's public form, as the plugin hands it to
 * Submissions::submit(): a title, a body and, when given, fields, terms and
 * who sent it. Teme keeps it as an item waiting for review; it is never
 * published by that path.
 *
 * @since 1.3.0
 */
final readonly class Submission
{
    /**
     * @param  string  $type  the content type's key, such as post
     * @param  string  $title  its title, at most 512 characters
     * @param  array<string, mixed>  $body  a Tiptap document (`type: doc`); HtmlToDocument turns HTML into one
     * @param  array<string, mixed>  $fields  by field handle
     * @param  array<string, list<string>>  $terms  slugs or names of terms the site already has, by taxonomy; a submission never makes a term
     * @param  string|null  $from  who sent it, as the visitor gave it, such as a name and an email address; at most 255 characters, shown to the person who reviews it
     *
     * @since 1.3.0
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $body,
        public array $fields = [],
        public array $terms = [],
        public ?string $from = null,
    ) {}
}
