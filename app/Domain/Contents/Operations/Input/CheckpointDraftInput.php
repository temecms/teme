<?php

/**
 * Whose autosave, in which article, to gather into a revision.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use App\Domain\Contents\Slugs;
use Teme\Contracts\Operations\OperationInput;

final readonly class CheckpointDraftInput implements OperationInput
{
    /** The address to save with this checkpoint, or null to leave it. */
    public ?string $slug;

    public function __construct(
        public string $contentId,
        public ?string $actorId,
        ?string $slug = null,
    ) {
        $this->slug = $slug === null ? null : Slugs::normal($slug);
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
