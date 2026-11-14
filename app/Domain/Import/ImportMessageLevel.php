<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * How serious a message from an import run is: a warning the person may
 * want to read, or an error that kept an object from being imported.
 */
enum ImportMessageLevel: string
{
    case Warning = 'warning';
    case Error = 'error';
}
