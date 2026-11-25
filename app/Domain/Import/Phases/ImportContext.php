<?php

/**
 * What every phase of one run is given: the run, its kind and actor, a catch-up's starting time, the pause between pages, the choices made before it began (Tools › Import's choices step), the export file it reads, if any, and how many lanes its media list is read in.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

use App\Domain\Import\ImportRunKind;
use App\Domain\Support\Security\JobActor;
use Carbon\CarbonImmutable;

final readonly class ImportContext
{
    /**
     * @param  array<string, mixed>  $choices
     */
    public function __construct(
        public string $runId,
        public string $sourceId,
        public ImportRunKind $kind,
        public JobActor $actor,
        public ?CarbonImmutable $modifiedAfter,
        public int $pauseMs,
        public array $choices,
        public ?string $fileId = null,
        public int $mediaLanes = 1,
    ) {}

    /**
     * The person acting, or null for the operator.
     */
    public function actorId(): ?string
    {
        return $this->actor->userId;
    }

    /**
     * Whether the run reads an uploaded export file rather than the companion.
     *
     * @since 1.4.0
     */
    public function readsFile(): bool
    {
        return $this->fileId !== null;
    }

    /**
     * Whether the run brings its articles first and their media files after,
     * in a picture pass of their own (#716).
     *
     * @since next
     */
    public function bringsMediaLater(): bool
    {
        return $this->choice('media') === 'later';
    }

    /**
     * Whether the run's posts keep pointing at the old site's media files
     * that Teme has no file for yet: when they come after the articles, or
     * when an export file's media stay on the old site as links.
     *
     * @since next
     */
    public function keepsOldSiteMedia(): bool
    {
        return $this->bringsMediaLater() || ($this->readsFile() && $this->choice('media') === 'links');
    }

    /**
     * One of the choices made before the run began, or the default.
     */
    public function choice(string $key, mixed $default = null): mixed
    {
        return $this->choices[$key] ?? $default;
    }
}
