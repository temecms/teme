<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use RuntimeException;

/**
 * A plugin's list that took longer than its budget, or was not asked
 * because the page's budget was spent.
 */
final class ListTooSlow extends RuntimeException
{
    public function __construct(string $id, int $milliseconds)
    {
        parent::__construct("The list {$id} took {$milliseconds} ms.");
    }
}
