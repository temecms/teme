<?php

/**
 * Thrown when a plugin's content or media write is refused.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use RuntimeException;

/**
 * Thrown when a plugin's content or media write is refused. Its message is
 * one plain sentence; nothing was written.
 *
 * @since 1.0.0
 */
final class WriteRefused extends RuntimeException {}
