<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Greedy;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

if (PHP_VERSION_ID > 80000) {
    /**
     * A plugin declared only when its file decides to.
     */
    final class Conditional implements Plugin
    {
        public function register(Hooks $hooks): void {}
    }
}
