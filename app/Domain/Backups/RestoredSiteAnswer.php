<?php

declare(strict_types=1);

namespace App\Domain\Backups;

/**
 * A person's answer after a restore: this server is now the site, so its backups resume; or it is a copy, so the backup places it brought back are removed.
 */
enum RestoredSiteAnswer: string
{
    case ThisIsTheSite = 'site';
    case ThisIsACopy = 'copy';
}
