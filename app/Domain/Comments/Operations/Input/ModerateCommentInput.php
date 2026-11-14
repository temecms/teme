<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * Which comment to move, where to (a status a moderator may choose, or
 * ModerateComment::RESTORE), and who is asking.
 */
final readonly class ModerateCommentInput implements OperationInput
{
    public function __construct(
        public string $commentId,
        public string $to,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
