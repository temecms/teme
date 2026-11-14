<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Resolve;

/**
 * A question answered by a string or nothing, for the dispatcher's tests.
 */
#[HookContract]
final readonly class Answered implements Resolve
{
    public function __construct(public string $question = '') {}

    public static function valueType(): string
    {
        return '?string';
    }
}
