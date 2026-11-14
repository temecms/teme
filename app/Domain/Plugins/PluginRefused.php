<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use RuntimeException;

/**
 * Why Teme stopped a plugin while it started (I-3353): it asked for
 * something Teme doesn't give it, or what it contributes was refused.
 */
final class PluginRefused extends RuntimeException {}
