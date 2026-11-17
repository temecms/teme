<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use RuntimeException;

/**
 * A call into a plugin's area that took longer than its budget, or was not
 * made because the page's budget was spent.
 */
final class AreaTooSlow extends RuntimeException
{
    public function __construct(string $what, int $milliseconds)
    {
        parent::__construct("The area's {$what} took {$milliseconds} ms.");
    }
}
