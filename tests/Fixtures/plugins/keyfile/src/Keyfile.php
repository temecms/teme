<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Keyfile;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin that runs no code of its own: core answers its root files.
 */
final class Keyfile implements Plugin
{
    public function register(Hooks $hooks): void {}
}
