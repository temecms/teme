<?php

/**
 * Why Teme stopped a plugin while it started (I-3353): it asked for
 * something Teme doesn't give it, or what it contributes was refused.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use RuntimeException;

final class PluginRefused extends RuntimeException {}
