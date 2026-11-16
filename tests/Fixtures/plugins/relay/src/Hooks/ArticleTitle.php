<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Relay\Hooks;

use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Transform;

/**
 * Relay's own Transform hook: an article's title before it is written.
 */
#[HookContract]
final readonly class ArticleTitle implements Transform
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
