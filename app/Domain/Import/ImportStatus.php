<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * The lasting state of a source object's map row: what a run that meets it does
 * with it (I-332).
 */
enum ImportStatus: string
{
    case Imported = 'imported';
    case NeedsUpdate = 'needs_update';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
