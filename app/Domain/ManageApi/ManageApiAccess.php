<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

/**
 * Who manages the management API: whoever holds api.keys.manage, which
 * only administrators are given (docs/proposals/management-api.md).
 */
final class ManageApiAccess
{
    /** The permission that makes and revokes keys and turns the API on. */
    public const string ABILITY = 'api.keys.manage';
}
