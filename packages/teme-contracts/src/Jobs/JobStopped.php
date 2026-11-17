<?php

declare(strict_types=1);

namespace Teme\Contracts\Jobs;

use RuntimeException;

/**
 * Thrown by progress() so a handler unwinds when its run must stop.
 *
 * @since 1.0.0
 */
final class JobStopped extends RuntimeException {}
