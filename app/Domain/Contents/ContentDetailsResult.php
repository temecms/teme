<?php

/**
 * What a quick edit did: whether the change went live at once, and whether it
 * was kept as a draft because other unpublished changes were waiting.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

final readonly class ContentDetailsResult
{
    public function __construct(
        public string $contentId,
        public bool $publishedNow,
        public bool $draftPending,
    ) {}
}
