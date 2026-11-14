<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Loud;

use RuntimeException;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

throw new RuntimeException('Loaded.');
exit(3);

/**
 * A plugin whose file throws, then exits, the moment it is loaded.
 */
final class Exits implements Plugin
{
    public function register(Hooks $hooks): void {}
}
