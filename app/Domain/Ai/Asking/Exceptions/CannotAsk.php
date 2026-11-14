<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Exceptions;

use RuntimeException;

/**
 * A request AI can't take now, though nothing is wrong with it: the task
 * isn't available, or the month's budget is spent. Its message is what the
 * person reads.
 */
final class CannotAsk extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('AI isn’t available for this right now.');
    }

    public static function budgetSpent(): self
    {
        return new self('This month’s AI budget has been spent, so AI is unavailable until the month ends.');
    }
}
