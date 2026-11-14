<?php

declare(strict_types=1);

namespace App\Domain\Types;

/**
 * Who may change the site's own content types, and whether the host has
 * locked them so they only arrive by file (I-411), and the gate a type's
 * list screens ask.
 */
final class TypeAccess
{
    public const string MANAGE = 'types.manage';

    /**
     * The gate a type's list screens ask, given the URL's segment: the
     * type's own create ability, or content.create for a segment no type
     * has, whose screens then answer 404 (I-413).
     */
    public const string OPEN_LIST = 'open-type-list';

    public const string LOCKED = 'Types are locked on this site (TEME_TYPES_LOCKED): change them where they’re made, and bring them here with php artisan teme:types:import.';

    /**
     * Whether the host has locked type changes for people.
     */
    public static function locked(): bool
    {
        return (bool) config('teme.types_locked', false);
    }
}
