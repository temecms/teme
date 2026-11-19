<?php

/**
 * The value `contents.author_sort` holds: the author's name folded for
 * sorting, so the lists can sort by author with an index (I-204). The
 * Operations that set an author or rename one write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

final class AuthorSort
{
    /**
     * The name lowercased, without surrounding space, at most 255 characters,
     * or '' when there is no author.
     */
    public static function of(?string $name): string
    {
        return $name === null ? '' : mb_substr(mb_strtolower(trim($name)), 0, 255);
    }
}
