<?php

/**
 * Thrown when a plugin's declared types file is missing, can't be read, or
 * isn't a version 1 teme-types file; its message says why in words.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Types;

use RuntimeException;

final class PluginTypesUnreadable extends RuntimeException {}
