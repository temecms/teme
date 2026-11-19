<?php

/**
 * One titled group of values in a person's export: a comment, an account, an
 * event.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Privacy;

/**
 * One titled group of values in a person's export: a comment, an account,
 * an event.
 *
 * @since 0.5.0
 */
final readonly class ExportGroup
{
    /**
     * @param  string  $title  what the group is, for the person: "Comment on “Harbour news”"
     * @param  array<string, string>  $values  label to value, each label once within the group
     *
     * @since 0.5.0
     */
    public function __construct(
        public string $title,
        public array $values,
    ) {}
}
