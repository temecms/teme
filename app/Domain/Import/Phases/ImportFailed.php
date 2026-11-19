<?php

/**
 * One object can't be imported; the run goes on, and the next full import tries it again: a catch-up only meets what changed on WordPress.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use App\Domain\Import\ImportMessageLevel;
use RuntimeException;

final class ImportFailed extends RuntimeException
{
    public readonly ImportMessage $why;

    public function __construct(ImportMessage $why)
    {
        $this->why = new ImportMessage($why->code, $why->message, $why->context, ImportMessageLevel::Error);

        parent::__construct($why->message);
    }
}
