<?php

declare(strict_types=1);

namespace Fixtures\NeedsExt;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin for the installer's tests, which does nothing.
 */
final class NeedsExt implements Plugin
{
    public function register(Hooks $hooks): void {}
}
