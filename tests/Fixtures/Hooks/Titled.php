<?php

declare(strict_types=1);

namespace Tests\Fixtures\Hooks;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Transform;

/**
 * A transform of a title, for the dispatcher's tests.
 */
#[HookContract()]
final readonly class Titled implements Transform
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
