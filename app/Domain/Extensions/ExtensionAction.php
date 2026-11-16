<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

/**
 * What a request asks the installer to do: change one package, go back to
 * the release before, or rebuild the current one for a new Teme.
 */
enum ExtensionAction: string
{
    case Install = 'install';
    case Update = 'update';
    case Remove = 'remove';
    case Rollback = 'rollback';
    case Rebuild = 'rebuild';

    /** Whether the request names a package; a rollback and a rebuild don't. */
    public function namesPackage(): bool
    {
        return $this !== self::Rollback && $this !== self::Rebuild;
    }
}
