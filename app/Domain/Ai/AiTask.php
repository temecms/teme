<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Support\Operations\BaseOperation;

/**
 * One thing AI may be asked to do (I-316), registered
 * under AiTasks::TAG from its part's own provider. It is made at every boot,
 * to build Settings › AI, so its constructor reads no table.
 */
interface AiTask
{
    /**
     * Its key, lower-case words joined by hyphens, unique among tasks.
     */
    public function key(): string;

    /**
     * Its name, as people read it: "Rewrite", "Alt text".
     */
    public function label(): string;

    /**
     * What it is asked about: an item or a media item.
     */
    public function subject(): AiSubjectType;

    /**
     * What a model needs to do it; only models with all of it are offered.
     *
     * @return list<AiCapability>
     */
    public function capabilities(): array;

    /**
     * Its prompt, whose hash must match, or the task stops.
     */
    public function prompt(): AiPrompt;

    /**
     * How long a model may take: 30 for text, 45 with images (I-320).
     */
    public function timeoutSeconds(): int;

    public function maxOutputTokens(): int;

    /**
     * The JSON schema an answer follows, or null for plain text.
     *
     * @return array<string, mixed>|null
     */
    public function outputSchema(): ?array;

    /**
     * The named fields, and any images, of the one subject asked about, read
     * by its IDs; nothing else (I-316).
     */
    public function input(AiSubject $subject): AiInput;

    /**
     * The answer, checked again here (I-316), as named values a person may
     * use: ['text' => …], or one a field.
     *
     * @return array<string, string>
     *
     * @throws AiFailure an invalid output, when the answer fails the check
     */
    public function check(AiResult $result): array;

    /**
     * The Operation that applies what a person chooses to use.
     *
     * @return class-string<BaseOperation>
     */
    public function appliedBy(): string;
}
