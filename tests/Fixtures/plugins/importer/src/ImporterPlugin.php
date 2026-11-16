<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Importer;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that brings its own data across from WordPress through
 * its importer, and listens for nothing.
 */
final class ImporterPlugin implements Plugin
{
    public function register(Hooks $hooks): void {}
}
