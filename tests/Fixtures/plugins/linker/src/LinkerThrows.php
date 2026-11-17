<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Linker;

use RuntimeException;
use Teme\Contracts\Content\ArticleLinks;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * Fails every time it is asked.
 */
final class LinkerThrows implements Plugin
{
    public function register(Hooks $hooks): void
    {
        $hooks->on(ArticleLinks::class, fn (array $attributes, ArticleLinks $hook): array => throw new RuntimeException('Linker lost its rules.'));
    }
}
