<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Autoloads;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin whose composer.json would run it even while disabled.
 */
final class Autoloads implements Plugin
{
    public function register(Hooks $hooks): void {}
}
