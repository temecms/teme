<?php

/**
 * One request's hand-off from the job to the page (I-320): who asked, about
 * what, where it stands, and, once ready, the checked answer and its
 * target's hash. Stored as arrays in the disposable cache, never kept.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Asking;

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;

final readonly class Suggestion
{
    public const string WORKING = 'working';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    /** Why a request failed when no model was asked: the item or image went first. */
    public const string GONE = 'gone';

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $sentTo
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $task,
        public AiSubject $subject,
        public string $state,
        public array $values = [],
        public ?string $hash = null,
        public ?string $reason = null,
        public array $sentTo = [],
    ) {}

    /**
     * The same request, moved on.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $sentTo
     */
    public function with(string $state, array $values, ?string $hash, ?string $reason, array $sentTo): self
    {
        return new self($this->id, $this->userId, $this->task, $this->subject, $state, $values, $hash, $reason, $sentTo);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user' => $this->userId,
            'task' => $this->task,
            'subject' => [
                'type' => $this->subject->type->value,
                'id' => $this->subject->id,
                'revision' => $this->subject->revisionId,
                'target' => $this->subject->target,
                'options' => $this->subject->options,
            ],
            'state' => $this->state,
            'values' => $this->values,
            'hash' => $this->hash,
            'reason' => $this->reason,
            'sentTo' => $this->sentTo,
        ];
    }

    /**
     * @param  array<mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $subject = is_array($stored['subject'] ?? null) ? $stored['subject'] : [];
        $text = fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new self(
            (string) $text($stored['id'] ?? null),
            (string) $text($stored['user'] ?? null),
            (string) $text($stored['task'] ?? null),
            new AiSubject(
                AiSubjectType::from((string) $text($subject['type'] ?? null)),
                (string) $text($subject['id'] ?? null),
                $text($subject['revision'] ?? null),
                $text($subject['target'] ?? null),
                array_filter(is_array($subject['options'] ?? null) ? $subject['options'] : [], fn (mixed $value): bool => is_string($value) || is_int($value)),
            ),
            (string) $text($stored['state'] ?? null),
            array_filter(is_array($stored['values'] ?? null) ? $stored['values'] : [], 'is_string'),
            $text($stored['hash'] ?? null),
            $text($stored['reason'] ?? null),
            array_values(array_filter(is_array($stored['sentTo'] ?? null) ? $stored['sentTo'] : [], 'is_string')),
        );
    }

    /**
     * What the page reads: where the request stands, the values when ready,
     * one plain sentence when failed, and who it was sent to (I-1304).
     *
     * @return array<string, mixed>
     */
    public function forPage(): array
    {
        return match ($this->state) {
            self::READY => ['state' => self::READY, 'values' => $this->values, 'sentTo' => $this->sentTo],
            self::FAILED => ['state' => self::FAILED, 'message' => $this->sentence(), 'sentTo' => $this->sentTo],
            default => ['state' => self::WORKING, 'sentTo' => $this->sentTo],
        };
    }

    /**
     * A failed request's sentence: Teme's own words, never a provider's.
     */
    public function sentence(): string
    {
        if ($this->reason === self::GONE) {
            return 'This was deleted before AI could answer.';
        }

        return (AiFailureType::tryFrom((string) $this->reason) ?? AiFailureType::Unavailable)->sentence();
    }
}
