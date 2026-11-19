<?php

/**
 * A person's answer after a restore: this server is now the site, so its backups resume; or it is a copy, so the backup places it brought back are removed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

enum RestoredSiteAnswer: string
{
    case ThisIsTheSite = 'site';
    case ThisIsACopy = 'copy';
}
