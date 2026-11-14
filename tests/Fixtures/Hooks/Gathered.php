<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\HookContract;

/**
 * A collection of strings, for the dispatcher's tests.
 */
#[HookContract]
final readonly class Gathered implements Collect
{
    public function __construct(public string $what = '') {}

    public static function itemType(): string
    {
        return 'string';
    }
}
