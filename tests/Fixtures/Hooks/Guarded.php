<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\Gate;
use Teme\Contracts\Hooks\HookContract;

/**
 * A gate, for the dispatcher's tests.
 */
#[HookContract]
final readonly class Guarded implements Gate
{
    public function __construct(public string $what = '') {}
}
