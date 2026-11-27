<?php

/**
 * The one way Teme spells a reader's site address, so the IDs a reader's
 * items keep and the source they join are found by the same address.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Reader;

/**
 * Normalises a reader's site address: its spaces and any trailing slash are
 * dropped, so an export that names its site with or without one is the same site.
 *
 * @since next
 */
final class ReaderSite
{
    /**
     * The address in the form Teme keeps it, without a trailing slash.
     *
     * @since next
     */
    public static function address(string $address): string
    {
        return rtrim(trim($address), '/');
    }
}
