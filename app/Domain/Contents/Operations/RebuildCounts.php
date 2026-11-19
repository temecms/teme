<?php

/**
 * Recomputes the status counts, each term's published and live-item counts —
 * in total and per content type — the media counts, and each item's comment
 * counts, conversations and last comment, from the rows, and reports how many
 * differed: the safety net under the maintained counts (I-141). Run weekly,
 * and by the operator at any time; it holds a lock on content_counts,
 * media_counts, term_counts and comment_stats while it fixes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\Operations\Concerns\RecountsContent;
use App\Domain\Contents\Operations\Input\RebuildCountsInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

final class RebuildCounts extends BaseOperation
{
    use RecountsContent;

    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator rebuilds counts.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * @return array{drifted: int, fixed: bool}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RebuildCountsInput $input */
        return $this->recount($input->fix);
    }
}
