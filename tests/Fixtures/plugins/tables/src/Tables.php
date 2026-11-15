<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Tables;

use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that owns two tables of its own.
 */
final class Tables implements Plugin
{
    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentPublished::class, function (ContentPublished $published): void {});
    }
}
