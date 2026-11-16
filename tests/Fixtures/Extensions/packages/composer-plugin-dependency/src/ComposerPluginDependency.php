<?php

declare(strict_types=1);

namespace Fixtures\ComposerPluginDependency;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin for the installer's tests, which does nothing.
 */
final class ComposerPluginDependency implements Plugin
{
    public function register(Hooks $hooks): void {}
}
