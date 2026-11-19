<?php

/**
 * One batch of targets whose addresses to bring in step: their kind
 * (`content`, `term`, `user` or `person`), their IDs, and who asked for it (I-392);
 * with `under`, the page they all sit under, whose branch is moving (I-3363).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RebuildPathsInput implements OperationInput
{
    /**
     * @param  list<string>  $ids
     */
    public function __construct(
        public string $kind,
        public array $ids,
        public ?string $actorId,
        public ?string $under = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
