<?php

/**
 * What an autosave did: when it was kept, or that it was refused because the
 * article moved on underneath it, the working revision as it now is, who
 * else has unsaved work in the article, and which link addresses were stored
 * differently.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

final readonly class SaveAutosaveResult
{
    /**
     * @param  list<array{id: int, name: string}>  $otherEditors
     * @param  list<array{from: string, to: string|null}>  $links  the link addresses the saved document holds differently (I-452)
     */
    public function __construct(
        public ?string $savedAt,
        public bool $stale,
        public ?string $workingRevisionId,
        public array $otherEditors,
        public array $links = [],
    ) {}
}
