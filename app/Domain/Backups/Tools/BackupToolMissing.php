<?php

/**
 * restic, pg_dump or pg_restore isn't where the configuration says (I-366),
 * in words that say what to install or set.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use RuntimeException;

final class BackupToolMissing extends RuntimeException
{
    public static function restic(string $configured): self
    {
        $pinned = BackupTools::RESTIC_VERSION;

        return new self(BackupTools::isPath($configured)
            ? "There’s no program at {$configured}, where TEME_RESTIC_BINARY says restic is. Install restic {$pinned} there, or correct the path."
            : "restic isn’t on the PATH. Install restic {$pinned}, or set TEME_RESTIC_BINARY to where it is.");
    }

    public static function postgres(PostgresProgram $program, string $folder): self
    {
        $major = BackupTools::POSTGRES_MAJOR;

        return new self($folder === ''
            ? "{$program->value} isn’t on the PATH. Install PostgreSQL {$major}’s command-line tools, or set TEME_POSTGRES_BINDIR to their folder."
            : "There’s no {$program->value} in {$folder}, where TEME_POSTGRES_BINDIR says PostgreSQL’s tools are. Install PostgreSQL {$major}’s command-line tools there, or correct the folder.");
    }
}
