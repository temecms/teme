<?php

/**
 * How serious a message from an import run is: a warning the person may
 * want to read, or an error that kept an object from being imported.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import;

enum ImportMessageLevel: string
{
    case Warning = 'warning';
    case Error = 'error';
}
