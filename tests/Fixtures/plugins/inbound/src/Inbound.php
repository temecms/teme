<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Inbound;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * Fixture plugin that declares public routes for the public-routes tests.
 */
final class Inbound implements Plugin
{
    public function register(Hooks $hooks): void {}
}
