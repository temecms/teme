<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Greedy;

use Acme\Elsewhere\BasePlugin;
use Teme\Contracts\Plugins\Plugin;

/**
 * A plugin whose constructor comes from a parent outside its own code.
 */
final class Inherits extends BasePlugin implements Plugin {}
