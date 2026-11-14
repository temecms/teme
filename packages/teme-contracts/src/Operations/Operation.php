<?php

declare(strict_types=1);

namespace Teme\Contracts\Operations;

/**
 * The single entry point for every state mutation in the CMS.
 *
 * Admin controllers, API endpoints, queue jobs, console commands and AI
 * tools all call operations. None of them touch persistence directly.
 */
interface Operation
{
    public function execute(OperationInput $input): mixed;
}
