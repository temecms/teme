<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Carbon\CarbonInterface;
use Teme\Contracts\Operations\OperationInput;

/**
 * What an Operation acting on one whole comment needs: which comment, and
 * who is asking: a null actor is the operator, whom only
 * DeleteCommentPermanently accepts. `notChangedAfter` is a cut-off: the comment
 * is deleted only if its status hasn't changed since (1.8c′'s D3).
 */
final readonly class CommentActionInput implements OperationInput
{
    public function __construct(
        public string $commentId,
        public ?string $actorId,
        public ?CarbonInterface $notChangedAfter = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
