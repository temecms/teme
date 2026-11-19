<?php

/**
 * A reply written in the admin (1.8c′'s D11): the comment it answers, the
 * words as typed, and who is writing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ReplyToCommentInput implements OperationInput
{
    public function __construct(
        public string $commentId,
        public string $text,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
