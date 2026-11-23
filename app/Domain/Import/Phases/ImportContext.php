<?php

/**
 * What every phase of one run is given: the run, its kind and actor, a catch-up's starting time, the pause between pages, the choices made before it began (Tools › Import's choices step), and the export file it reads, if any.
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
     * One of the choices made before the run began, or the default.
     */
    public function choice(string $key, mixed $default = null): mixed
    {
        return $this->choices[$key] ?? $default;
    }
}
