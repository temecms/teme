<?php

/**
 * A suggestion a person chose to use: the request's id; for a text block,
 * the revision the editor's work is based on, the block's path now, and the
 * block as the editor would have it after using the suggestion; and who is
 * using it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ApplyAiSuggestionInput implements OperationInput
{
    /**
     * @param  array<string, mixed>|null  $block
     * @param  list<string>|null  $fields  for a suggestion of several fields, the handles of those to use
     */
    public function __construct(
        public string $id,
        public ?string $actorId,
        public ?string $expectedRevisionId = null,
        public ?string $path = null,
        public ?array $block = null,
        public ?array $fields = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
