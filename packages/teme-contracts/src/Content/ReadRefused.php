<?php

/**
 * Thrown when a plugin's read of a draft is refused.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use RuntimeException;

/**
 * Thrown when a plugin's read of a draft is refused. Its message is one
 * plain sentence; nothing was read.
 *
 * @since 1.0.0
 */
final class ReadRefused extends RuntimeException {}
