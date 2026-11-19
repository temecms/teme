<?php

/**
 * Makes an image's variants on the queue and records them through
 * RecordMediaVariants, as the person whose change caused it
 * (docs/architecture/media.md, "The processing pipeline"), or, for an image's
 * first use as a featured or social image, as the person who used it, with
 * the item ($usedBy) that gives them the right (I-855). Idempotent: a
 * second run writes the same files and records the same map. Its actor
 * (I-392) is the person, or the operator made at the shell, and bind() gives
 * a worker the operator's elevation only for the operator. It runs on the long queue,
 * whose retry window outlasts its timeout (docs/architecture/media.md).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Jobs;

use App\Domain\Media\Exceptions\StaleMediaException;
use App\Domain\Media\MediaVariants;
use App\Domain\Media\Operations\Input\RecordMediaVariantsInput;
use App\Domain\Media\Operations\RecordMediaVariants;
use App\Domain\Media\VariantMaker;
use App\Domain\Support\Security\JobActor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Str;
use Throwable;

#[Connection('database-long')]
#[Queue('long')]
final class GenerateMediaVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Past the database connection's 90 seconds, so the job runs on database-long, whose window is 360. */
    public int $timeout = 300;

    public function __construct(
        public readonly string $mediaId,
        public readonly int $fileVersion,
        public readonly JobActor $actor,
        public readonly ?string $usedBy = null,
    ) {}

    public function handle(VariantMaker $maker, RecordMediaVariants $record): void
    {
        $this->actor->bind();

        $variants = $maker->make($this->mediaId, $this->fileVersion);

        if ($variants === null) {
            return;
        }

        try {
            $result = $record->execute(new RecordMediaVariantsInput($this->mediaId, $this->fileVersion, MediaVariants::SET, $variants, null, $this->actor->userId, $this->usedBy));
        } catch (StaleMediaException) {
            $maker->discard(array_column($variants, 'path'));

            return;
        }

        $maker->discard($result->obsoletePaths);
    }

    public function failed(?Throwable $exception): void
    {
        $this->actor->bind();

        try {
            app(RecordMediaVariants::class)->execute(new RecordMediaVariantsInput(
                $this->mediaId,
                $this->fileVersion,
                MediaVariants::SET,
                null,
                Str::limit((string) $exception?->getMessage(), 500),
                $this->actor->userId,
                $this->usedBy,
            ));
        } catch (Throwable) {
            // Replaced, deleted, or the person may no longer change it: the
            // item keeps its pending state, and an administrator retries.
        }
    }
}
