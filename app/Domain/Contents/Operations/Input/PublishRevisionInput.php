<?php

/**
 * What `PublishRevision` needs: which content, which of its revisions to
 * publish, who is asking, and whether their autosave on that revision goes.
 * A deferred publish — a schedule, an approval — keeps it: nobody pressed
 * Publish at that moment, and they may have typed since. A scheduled run
 * skips the required-field check, which the schedule already passed, and
 * keeps a page under it at its old address when the new one is taken,
 * since nobody is there to read a refusal (I-3363).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class PublishRevisionInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $revisionId,
        public ?string $actorId,
        public bool $clearAutosave = true,
        public bool $checkRequired = true,
        public bool $keepClashingChildren = false,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
