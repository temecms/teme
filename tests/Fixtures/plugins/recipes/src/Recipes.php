<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Recipes;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin whose content types are all data, in its teme-types file.
 */
final class Recipes implements Plugin
{
    public function register(Hooks $hooks): void {}
}
