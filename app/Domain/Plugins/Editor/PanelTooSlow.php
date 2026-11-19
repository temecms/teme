<?php

/**
 * A plugin's editor panel took longer than its budget. Recorded under the
 * plugin.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use RuntimeException;

final class PanelTooSlow extends RuntimeException
{
    public function __construct(string $panel, int $milliseconds, int $budget = PanelReads::CALL_BUDGET_MS)
    {
        parent::__construct("{$panel} took {$milliseconds} ms, over its {$budget} ms budget.");
    }
}
