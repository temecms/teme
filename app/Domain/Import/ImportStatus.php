<?php

/**
 * The lasting state of a source object's map row: what a run that meets it does
 * with it (I-332).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import;

enum ImportStatus: string
{
    case Imported = 'imported';
    case NeedsUpdate = 'needs_update';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
