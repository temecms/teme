<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\BrokenStart;

use RuntimeException;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin that subscribes, then throws before its register() ends.
 */
final class BrokenStart implements Plugin
{
    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentPublished::class, function (ContentPublished $published): void {});

        throw new RuntimeException('The broken plugin broke.');
    }
}
