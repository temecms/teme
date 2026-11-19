<?php

/**
 * A phase that runs once, or in several jobs, each starting at the cursor
 * the one before left: the address settings, the second pass, the check.
 * Idempotent: a run carried on runs it again from the start.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use Illuminate\Auth\Access\AuthorizationException;

interface StepPhase extends ImportPhase
{
    /**
     * @throws ImportStopped when the whole run must stop
     * @throws SourceRefused when the old site refuses the reader
     * @throws AuthorizationException when the actor may no longer import
     */
    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult;
}
