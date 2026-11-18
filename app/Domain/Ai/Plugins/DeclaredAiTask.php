<?php

/**
 * One AI task a plugin declares in its manifest's `contributes.aiTasks`:
 * its id, the label and description people read, what a model needs to do
 * it, what it sends (its own brief, a draft, or published text), and the
 * most tokens an answer may hold.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Plugins;

use App\Domain\Ai\AiCapability;

final readonly class DeclaredAiTask
{
    /**
     * @param  list<AiCapability>  $needs
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public array $needs,
        public string $sends,
        public int $maxOutputTokens,
    ) {}

    /**
     * Returns what the task needs, as the consent record keeps it: each
     * capability's value, in the order declared.
     *
     * @return list<string>
     */
    public function needValues(): array
    {
        return array_map(fn (AiCapability $need): string => $need->value, $this->needs);
    }
}
