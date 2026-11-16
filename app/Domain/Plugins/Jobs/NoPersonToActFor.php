<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use RuntimeException;

/**
 * Thrown by a run's JobContext::personId() when the run acts for nobody,
 * such as one the operator queued: RunPluginJob fails the run at once with
 * its sentence, never trying it again.
 */
final class NoPersonToActFor extends RuntimeException {}
