<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\HookContract;

/**
 * A collection whose contract can't be built, its failure given as a
 * string: core's own fault, for the dispatcher's depth tests.
 */
#[HookContract(failure: 'loudly')]
final readonly class Misdeclared implements Collect
{
    public static function itemType(): string
    {
        return 'string';
    }
}
