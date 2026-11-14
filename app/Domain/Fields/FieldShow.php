<?php

declare(strict_types=1);

namespace App\Domain\Fields;

/**
 * Where the default theme lists a field on an item's page: after the
 * article, before it, or not at all. A theme may still place any field
 * itself (I-3322).
 */
enum FieldShow: string
{
    case After = 'after';
    case Before = 'before';
    case Hidden = 'hidden';
}
