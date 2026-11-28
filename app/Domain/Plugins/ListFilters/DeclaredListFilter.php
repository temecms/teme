<?php

/**
 * One list filter an enabled plugin declared for a content type: its key on the
 * list, its options in the person's language, and the plugin table, column and
 * item column its rows are matched by.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\ListFilters;

final readonly class DeclaredListFilter
{
    /**
     * @param  array<string, string>  $options  value => label, in declared order
     */
    public function __construct(
        public string $key,
        public string $owner,
        public string $label,
        public array $options,
        public string $table,
        public string $column,
        public string $item,
    ) {}
}
