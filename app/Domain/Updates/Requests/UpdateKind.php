<?php

/**
 * What an update request asks for: a newer release, or the release deployed before the one running.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Requests;

enum UpdateKind: string
{
    case Update = 'update';
    case Rollback = 'rollback';
}
