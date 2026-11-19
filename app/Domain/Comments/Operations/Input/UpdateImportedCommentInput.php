<?php

/**
 * What UpdateImportedComment needs: the comment, the status and updated_at the
 * import last wrote, WordPress's status, writer and words now, and who is
 * importing (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use Carbon\CarbonImmutable;
use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

final readonly class UpdateImportedCommentInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $body  a restricted comment document (I-267)
     */
    public function __construct(
        public string $commentId,
        public string $expectedStatus,
        public CarbonImmutable $expectedEditedAt,
        public string $status,
        public ?string $authorName,
        #[SensitiveParameter] public ?string $authorEmail,
        public ?string $authorUrl,
        public ?int $authorUserId,
        public array $body,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
