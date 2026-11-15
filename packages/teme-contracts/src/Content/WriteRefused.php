<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use RuntimeException;

/**
 * Thrown when a plugin's content or media write is refused. Its message is
 * one plain sentence; nothing was written.
 *
 * @since 0.9.0
 */
final class WriteRefused extends RuntimeException {}
