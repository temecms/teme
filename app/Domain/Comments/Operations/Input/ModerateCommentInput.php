<?php

/**
 * Which comment to move, where to (a status a moderator may choose, or
 * ModerateComment::RESTORE), and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
