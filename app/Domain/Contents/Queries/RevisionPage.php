<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

/**
 * One page of an article's history, newest first, and the cursor for the
 * page of older revisions after it.
 */
final readonly class RevisionPage
{
    /**
     * @param  list<array{id: string, kind: string, origin: string, wordCount: int, wordDelta: int|null, author: string|null, createdAt: string, isPublished: bool, isWorking: bool}>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $older,
    ) {}
}
