<?php

declare(strict_types=1);

namespace App\Domain\Contents;

/**
 * What a quick edit did: whether the change went live at once, and whether it
 * was kept as a draft because other unpublished changes were waiting.
 */
final readonly class ContentDetailsResult
{
    public function __construct(
        public string $contentId,
        public bool $publishedNow,
        public bool $draftPending,
    ) {}
}
