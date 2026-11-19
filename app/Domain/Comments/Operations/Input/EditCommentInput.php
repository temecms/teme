<?php

/**
 * Quick edit's fields for one comment (1.8c′'s D9): which comment, the name,
 * email address, website and words as typed, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class EditCommentInput implements OperationInput
{
    public function __construct(
        public string $commentId,
        public string $name,
        public string $email,
        public ?string $url,
        public string $text,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
