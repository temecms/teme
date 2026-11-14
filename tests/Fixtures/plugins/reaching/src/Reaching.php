<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Reaching;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin whose own class is clean, beside a file that isn't.
 */
final class Reaching implements Plugin
{
    public function register(Hooks $hooks): void {}
}
