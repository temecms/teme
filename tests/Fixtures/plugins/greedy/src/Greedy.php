<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Greedy;

use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin that asks for the whole container, which Teme never offers.
 */
final class Greedy implements Plugin
{
    public function __construct(public readonly Container $container) {}

    public function register(Hooks $hooks): void {}
}
