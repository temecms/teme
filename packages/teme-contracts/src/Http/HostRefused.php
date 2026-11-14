<?php

declare(strict_types=1);

namespace Teme\Contracts\Http;

use RuntimeException;

/**
 * Thrown when the address a plugin asked for may not be reached.
 *
 * @since 0.5.0
 */
final class HostRefused extends RuntimeException {}
