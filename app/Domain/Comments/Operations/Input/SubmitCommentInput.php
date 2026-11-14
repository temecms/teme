<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * A comment as its writer sent it (docs/studies/comments.md §3.4): the item
 * and the comment it answers, the words as typed, the name, email address and
 * website a guest gives, their browser's commenter token, and where it came
 * from. A signed-in writer's name and email address are their account's; a
 * null actor is a guest.
 */
final readonly class SubmitCommentInput implements OperationInput
{
    public function __construct(
        public string $contentId,
        public string $text,
        public ?string $parentId = null,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $url = null,
        public ?string $commenterToken = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
