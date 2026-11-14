<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * Where a place keeps its restic repository: a folder on this server, S3-compatible storage, or an SFTP server (I-360).
 */
enum BackupDestinationKind: string
{
    case Folder = 'folder';
    case S3 = 's3';
    case Sftp = 'sftp';

    /**
     * Whether backups here survive this server: a folder doesn't, whatever
     * disk it is on (I-355).
     */
    public function isOffServer(): bool
    {
        return $this !== self::Folder;
    }
}
