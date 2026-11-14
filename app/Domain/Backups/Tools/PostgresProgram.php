<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

/**
 * PostgreSQL's two programs a backup and a restore run (I-366).
 */
enum PostgresProgram: string
{
    case Dump = 'pg_dump';
    case Restore = 'pg_restore';

    /**
     * The program's file name on this system, with `.exe` on Windows.
     */
    public function fileName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? $this->value.'.exe' : $this->value;
    }
}
