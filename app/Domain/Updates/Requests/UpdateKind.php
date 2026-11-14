<?php

declare(strict_types=1);

namespace App\Domain\Updates\Requests;

/**
 * What an update request asks for: a newer release, or the release deployed before the one running.
 */
enum UpdateKind: string
{
    case Update = 'update';
    case Rollback = 'rollback';
}
