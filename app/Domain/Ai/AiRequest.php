<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

/**
 * One request to one model: its messages, the
 * images that go with the user's, an optional JSON schema the answer must
 * follow, a limit on the answer's tokens and a timeout. The timeout stays
 * under the queue worker's 60 seconds (I-320); a long request, made only
 * inside a long job whose worker allows 1,800, may wait up to 600.
 */
final readonly class AiRequest
{
    public const int MAX_TIMEOUT = 55;

    public const int MAX_LONG_TIMEOUT = 600;

    public const int MAX_OUTPUT_TOKENS = 64_000;

    /**
     * @param  list<AiMessage>  $messages  the system message first, if there is one
     * @param  list<AiImage>  $images
     * @param  array<string, mixed>|null  $schema  a JSON schema describing an object
     */
    public function __construct(
        public string $model,
        public array $messages,
        public int $maxOutputTokens,
        public int $timeoutSeconds,
        public array $images = [],
        public ?array $schema = null,
        public bool $long = false,
    ) {
        if (trim($model) === '') {
            throw new InvalidArgumentException('An AI request\'s model needs a name.');
        }

        $roles = array_map(fn (AiMessage $message): string => $message->role, $messages);

        if ($roles === [] || in_array('system', array_slice($roles, 1), true)) {
            throw new InvalidArgumentException('An AI request needs at least one message, the system message first if there is one.');
        }

        if ($maxOutputTokens < 1 || $maxOutputTokens > self::MAX_OUTPUT_TOKENS) {
            throw new InvalidArgumentException("An AI request's output limit [{$maxOutputTokens}] must be between 1 and 64000 tokens.");
        }

        $most = $long ? self::MAX_LONG_TIMEOUT : self::MAX_TIMEOUT;

        if ($timeoutSeconds < 1 || $timeoutSeconds > $most) {
            throw new InvalidArgumentException("An AI request's timeout [{$timeoutSeconds}] must be between 1 and {$most} seconds.");
        }

        if ($schema !== null && ($schema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('An AI request\'s schema must describe an object.');
        }
    }
}
