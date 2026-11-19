<?php

/**
 * A plugin's list that took longer than its budget, or was not asked
 * because the page's budget was spent.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

use RuntimeException;

final class ListTooSlow extends RuntimeException
{
    public function __construct(string $id, int $milliseconds)
    {
        parent::__construct("The list {$id} took {$milliseconds} ms.");
    }
}
