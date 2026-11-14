<?php

declare(strict_types=1);

namespace App\Domain\Comments\Operations\Input;

use App\Domain\Comments\CommentKind;
use Carbon\CarbonImmutable;
use SensitiveParameter;
use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportComment needs to bring one WordPress comment in: its item and
 * the comment it replied to, its kind, status, date and WordPress number, its
 * writer, its words, the address and browser it came from, and who is
 * importing (null for the operator).
 */
final readonly class ImportCommentInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $body  a restricted comment document (I-267)
     */
    public function __construct(
        public string $contentId,
        public ?string $parentId,
        public CommentKind $kind,
        public string $status,
        public CarbonImmutable $writtenAt,
        public int $legacyId,
        public ?string $authorName,
        #[SensitiveParameter] public ?string $authorEmail,
        public ?string $authorUrl,
        public ?int $authorUserId,
        public array $body,
        #[SensitiveParameter] public ?string $ip,
        #[SensitiveParameter] public ?string $userAgent,
        public ?string $actorId,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
