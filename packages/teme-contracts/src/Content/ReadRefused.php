<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use RuntimeException;

/**
 * Thrown when a plugin's read of a draft is refused. Its message is one
 * plain sentence; nothing was read.
 *
 * @since 0.9.0
 */
final class ReadRefused extends RuntimeException {}
