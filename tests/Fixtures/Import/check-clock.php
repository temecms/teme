<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Check;

use Tests\Fixtures\Import\CheckClock;

/**
 * microtime() as the address check calls it: PHP looks an unqualified call
 * up in the caller's namespace before the global one, so CheckStep reads
 * CheckClock, which is the real clock until a test makes it leap. Loaded
 * by tests/Pest.php before any test runs, since PHP keeps the first lookup.
 */
function microtime(bool $as_float = false): float|string
{
    return $as_float ? CheckClock::now() : \microtime(false);
}
