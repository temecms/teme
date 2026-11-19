<?php

/**
 * Where a listener's or a plugin's failure goes (I-3353), bound as the
 * dispatcher's ListenerFaults. For now it is only reported to the log, with
 * the plugin, the subscription and the hook as context: nothing counts
 * faults yet, so a spool would only grow.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Faults;

use App\Domain\Hooks\ListenerFaults;
use Illuminate\Support\Facades\Context;
use Throwable;

final readonly class SpooledFaults implements ListenerFaults
{
    /**
     * Reports one failure: $id is the subscription's id, or `{plugin}#boot`
     * for a failed start, and $hook the hook's class, or '' for none.
     */
    public function record(string $owner, string $id, string $hook, Throwable $failure): void
    {
        Context::scope(fn () => report($failure), ['plugin' => $owner, 'subscription' => $id, 'hook' => $hook]);
    }
}
