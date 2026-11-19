<?php

/**
 * Asks GitHub for Teme's releases once and records the answer (I-?? draft), unless this server has switched the check off.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Updates\Operations\Input\RecordReleaseCheckInput;
use App\Domain\Updates\Operations\RecordReleaseCheck;
use App\Domain\Updates\Queries\UpdateState;
use Carbon\CarbonImmutable;

final readonly class CheckForUpdates
{
    public function __construct(
        private GitHubReleases $github,
        private RecordReleaseCheck $record,
        private UpdateState $state,
    ) {}

    /**
     * Asks and records; null, having asked nothing, when the check is off.
     */
    public function run(): ?ReleaseCheck
    {
        if (config('teme.updates.check') !== true) {
            return null;
        }

        $check = $this->github->ask($this->state->check()['etag'] ?? null);
        $token = config('teme.updates.token');
        $this->record->execute(new RecordReleaseCheckInput($check, CarbonImmutable::now(), is_string($token) && $token !== ''));

        return $check;
    }
}
