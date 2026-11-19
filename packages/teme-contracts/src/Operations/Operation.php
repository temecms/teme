<?php

/**
 * The single entry point for every state mutation in the CMS.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Operations;

/**
 * The single entry point for every state mutation in the CMS.
 *
 * Admin controllers, API endpoints, queue jobs, console commands and AI
 * tools all call operations. None of them touch persistence directly.
 *
 * @since 0.1.0
 */
interface Operation
{
    /**
     * Returns what the operation made or changed, once it is committed.
     *
     * @since 0.1.0
     */
    public function execute(OperationInput $input): mixed;
}
