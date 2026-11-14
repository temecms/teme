<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use RuntimeException;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Transform;

/**
 * A transform whose value can't be read: core's own fault, for the
 * dispatcher's depth tests.
 */
#[HookContract]
final readonly class Unvalued implements Transform
{
    public static function valueType(): string
    {
        return 'string';
    }

    public function value(): mixed
    {
        throw new RuntimeException('No value.');
    }
}
