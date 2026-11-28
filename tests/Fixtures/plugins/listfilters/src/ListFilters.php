<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ListFilters;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that declares one list filter and owns the table it reads.
 */
final class ListFilters implements Plugin
{
    public function register(Hooks $hooks): void {}
}
