<?php

/**
 * One section of a search's results, a content type that has matches: its
 * name, how many results it holds, and the address that filters the search
 * to it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One section of a search's results: a content type with matches, its name
 * and count, and the address that filters the search to it, keeping the
 * query and the language.
 */
final readonly class SearchSection
{
    /**
     * @param  string  $type  the content type's key
     * @param  string  $label  the type's plural name, such as "Films"
     * @param  int  $count  how many results the type holds across all pages, capped as the search's total is
     * @param  string  $url  the address of the search filtered to this type, query and language kept
     * @param  bool  $current  whether this is the section the search is filtered to
     */
    public function __construct(
        public string $type,
        public string $label,
        public int $count,
        public string $url,
        public bool $current,
    ) {}
}
