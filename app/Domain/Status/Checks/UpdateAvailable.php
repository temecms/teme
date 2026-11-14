<?php

declare(strict_types=1);

namespace App\Domain\Status\Checks;

use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Updates\Queries\UpdateState;
use App\Domain\Updates\TemeVersion;

/**
 * Tells administrators that a newer Teme release exists on this site's channel (I-221): info,
 * or a warning when any newer release carries security fixes. It reads versions and marks only,
 * never the notes, since every admin page asks every check.
 */
final readonly class UpdateAvailable implements StatusCheck
{
    public function __construct(private UpdateState $state) {}

    public function ability(): string
    {
        return 'updates.manage';
    }

    public function label(): string
    {
        return __('Updates');
    }

    public function banner(): ?StatusBanner
    {
        $newer = $this->state->newerSummaries();

        if ($newer === []) {
            return null;
        }

        $newest = $newer[0]['version'];
        $current = TemeVersion::current();
        // Design A: the page the link leads to doesn't carry it.
        $onUpdates = request()->routeIs('tools.updates.index');
        $label = $onUpdates ? null : __('See the update');
        $action = $onUpdates ? null : route('tools.updates.index');

        if (array_any($newer, fn (array $release): bool => $release['security'])) {
            return new StatusBanner('warning', __('Teme :version is available, with security fixes. This site runs :current.', ['version' => $newest, 'current' => $current]), $label, $action, 60);
        }

        return new StatusBanner('info', __('Teme :version is available. This site runs :current.', ['version' => $newest, 'current' => $current]), $label, $action, 5);
    }
}
