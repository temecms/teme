<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use RuntimeException;

/**
 * A page insertion contributor that ran over its budget, or was skipped
 * because the page's budget was spent before it was asked.
 */
final class ContributorTooSlow extends RuntimeException
{
    public function __construct(string $owner, int $milliseconds, int $budget)
    {
        parent::__construct("{$owner}'s page insertions took {$milliseconds} ms, over the {$budget} ms budget.");
    }
}
