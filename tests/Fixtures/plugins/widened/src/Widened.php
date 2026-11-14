<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Widened;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin that asks for nothing at all, so it starts with the stand-ins.
 */
final class Widened implements Plugin
{
    public function register(Hooks $hooks): void {}
}
