<?php

/**
 * What SetDiscussion needs: the item, the state asked for (open, closed or
 * hidden), and who asks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SetDiscussionInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $discussion,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
