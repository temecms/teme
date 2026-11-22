<?php

/**
 * Reads an uploaded WordPress export file into import file rows, a part at a
 * time, as the person who uploaded it. Dispatched when an upload finishes.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File\Jobs;

use App\Domain\Support\Security\JobActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReadImportFile implements ShouldQueue
{
    use Queueable;

    /** Under the database queue's retry_after of 90 seconds, so no second worker takes the file while this one reads it. */
    public int $timeout = 75;

    public function __construct(
        public readonly string $fileId,
        public readonly JobActor $actor,
    ) {}

    /**
     * Reads the next part of the file. The upload's part ships this job empty
     * so the two can be built side by side; the reading's part fills it in.
     */
    public function handle(): void {}
}
