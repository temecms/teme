<?php

/**
 * The addresses the graph's @ids are made of (I-696): the site's home
 * address, and a site-relative address made absolute, both made at render
 * from the site's own address (rule 9), encoded as the head's are.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Addresses\PublicRequestPath;

final class GraphAddresses
{
    /** `{home}`: the site's address, with its slash. */
    public static function home(): string
    {
        return rtrim(url('/'), '/').'/';
    }

    /** A site-relative address, such as `/author/jane-doe/`, made absolute. */
    public static function absolute(string $path): string
    {
        return rtrim(url('/'), '/').PublicRequestPath::encode($path);
    }
}
