<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\CheckForUpdates;
use App\Domain\Updates\Queries\UpdateState;
use App\Domain\Updates\ReleaseCheck;
use App\Domain\Updates\TemeVersion;
use App\Domain\Updates\UpdateChannel;
use Illuminate\Console\Command;

/**
 * teme:updates:check — asks GitHub for Teme's releases as the operator
 * (I-?? draft), sending nothing about this site, and says what it found.
 */
final class CheckForUpdatesCommand extends Command
{
    /** @var string */
    protected $signature = 'teme:updates:check';

    /** @var string */
    protected $description = 'Ask GitHub for Teme’s releases, sending nothing about this site';

    public function handle(CheckForUpdates $check, UpdateState $state): int
    {
        $this->laravel->instance(Elevation::class, new OperatorElevation);
        $result = $check->run();

        if ($result === null) {
            $this->line('Update checks are off on this server (TEME_UPDATES_CHECK), so nothing was asked.');

            return self::SUCCESS;
        }

        if ($result->outcome === ReleaseCheck::FAILED) {
            $this->line((string) $result->error);

            return self::FAILURE;
        }

        $this->line('Asked GitHub for Teme’s releases.');
        $channel = UpdateChannel::current();
        $newest = $state->newest();
        $this->line($newest === null
            ? 'This site runs Teme '.TemeVersion::current().". There is no release on the {$channel} channel yet."
            : 'This site runs Teme '.TemeVersion::current().". The newest release on the {$channel} channel is {$newest->version}.");

        return self::SUCCESS;
    }
}
