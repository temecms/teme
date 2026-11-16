<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Relay\Hooks;

use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\HookContract;

/**
 * Relay's own Collect hook: extra prompt modules for an article, each a string.
 */
#[HookContract]
final readonly class PromptModules implements Collect
{
    public function __construct(public string $topic) {}

    public static function itemType(): string
    {
        return 'string';
    }
}
