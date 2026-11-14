<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Greedy;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin whose constructor a trait may supply.
 */
final class Traited implements Plugin
{
    use BuildsItself;

    public function register(Hooks $hooks): void {}
}
