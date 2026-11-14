<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\Failure;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Transform;

/**
 * A transform core marks as a check, whose failure fails the operation.
 */
#[HookContract(failure: Failure::FailOperation)]
final readonly class CheckedTitle implements Transform
{
    public function __construct(public string $title) {}

    public static function valueType(): string
    {
        return 'string';
    }

    public function value(): mixed
    {
        return $this->title;
    }
}
