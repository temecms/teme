<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\RelayPro;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Tests\Fixtures\Plugins\Relay\Hooks\DraftShared;

/**
 * Relay's add-on in a copy that listens to the hook needing content.read.
 */
final class RelayProReader implements Plugin
{
    public function register(Hooks $hooks): void
    {
        $hooks->on(DraftShared::class, function (DraftShared $hook): void {});
    }
}
