<?php

/**
 * The two forms an ID takes in the API (I-418): items and terms are
 * lower-case ULIDs, media upper-case ones.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

final class Ulids
{
    /** An item's or a term's ID. */
    public const string LOWER = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    /** A media file's ID. */
    public const string UPPER = '/^[0-9A-HJKMNP-TV-Z]{26}$/';
}
