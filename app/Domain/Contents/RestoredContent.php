<?php

declare(strict_types=1);

namespace App\Domain\Contents;

/**
 * What RestoreContent did: the item's slug now, the slug it had before if
 * that one had been taken while the item was in the trash, and whether it
 * left its translation group because another version holds its language
 * (I-3360), with that language's name.
 */
final readonly class RestoredContent
{
    public function __construct(
        public string $contentId,
        public string $slug,
        public ?string $previousSlug,
        public bool $leftGroup = false,
        public ?string $languageLabel = null,
    ) {}
}
