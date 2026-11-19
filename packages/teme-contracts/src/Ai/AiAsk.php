<?php

/**
 * One request: the plugin's instructions, its named inputs (each fenced by
 * core), an optional JSON schema.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Ai;

use InvalidArgumentException;

/**
 * One request: the plugin's instructions, its named inputs (each fenced by
 * core), an optional JSON schema.
 *
 * @since 1.0.0
 */
final readonly class AiAsk
{
    /**
     * The longest the instructions may be, in characters.
     *
     * @since 1.0.0
     */
    public const int MOST_INSTRUCTIONS = 100_000;

    /**
     * The longest a request may wait for its answer outside a long job, in seconds.
     *
     * @since 1.0.0
     */
    public const int MOST_SECONDS = 55;

    /**
     * The longest a request may wait for its answer inside a long job, in seconds.
     *
     * @since 1.0.0
     */
    public const int MOST_LONG_SECONDS = 600;

    /**
     * @param  string  $task  a task id the plugin declares
     * @param  string  $instructions  the system message, at most 100,000 characters
     * @param  array<string, string>  $inputs  by lower-case name with underscores; each sent inside its own <input> element, never as instructions
     * @param  array<string, mixed>|null  $schema  a JSON schema describing an object, for a task that needs structured-output
     * @param  int  $maxOutputTokens  1 to the task's declared maximum
     * @param  int  $timeoutSeconds  1 to 55, or up to 600 inside a long job
     * @param  string|null  $itemId  the item this request is about, recorded with it
     *
     * @throws InvalidArgumentException for a value outside these bounds
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $task,
        public string $instructions,
        public array $inputs,
        public ?array $schema = null,
        public int $maxOutputTokens = 4096,
        public int $timeoutSeconds = 55,
        public ?string $itemId = null,
    ) {
        if (trim($task) === '') {
            throw new InvalidArgumentException('An AI request names one of the plugin’s declared tasks.');
        }

        if (trim($instructions) === '' || mb_strlen($instructions) > self::MOST_INSTRUCTIONS) {
            throw new InvalidArgumentException('An AI request’s instructions must be 1 to 100,000 characters.');
        }

        foreach (array_keys($inputs) as $name) {
            if (preg_match('/^[a-z][a-z_]*$/', (string) $name) !== 1) {
                throw new InvalidArgumentException('An AI request’s inputs are each named in lower-case letters and underscores.');
            }
        }

        if ($schema !== null && ($schema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('An AI request’s schema must describe an object.');
        }

        if ($maxOutputTokens < 1 || $maxOutputTokens > 64_000) {
            throw new InvalidArgumentException('An AI request’s output limit must be between 1 and 64,000 tokens.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MOST_LONG_SECONDS) {
            throw new InvalidArgumentException('An AI request’s timeout must be between 1 and 600 seconds.');
        }

        if ($itemId !== null && preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $itemId) !== 1) {
            throw new InvalidArgumentException('An AI request’s item is a content ID.');
        }
    }
}
