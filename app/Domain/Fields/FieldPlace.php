<?php

/**
 * Where the editor draws a field: in the Details section by the general
 * renderer, or where core's own screens put it, or nowhere in the editor.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields;

enum FieldPlace: string
{
    case Details = 'details';
    case Core = 'core';
}
