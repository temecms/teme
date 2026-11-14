<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

/**
 * The one rule for the keys of AI providers, tasks and settings sections:
 * lower-case words joined by hyphens, each key once in its registry.
 */
final class AiKeys
{
    public const string PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /**
     * Checks one key against the pattern and the keys already seen, and adds
     * it to them.
     *
     * @param  string  $kind  as a sentence starts it: "AI task"
     * @param  array<string, true>  $seen
     */
    public static function check(string $kind, string $key, array &$seen): void
    {
        if (preg_match(self::PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("{$kind} [{$key}] needs a key of lower-case words joined by hyphens.");
        }

        if (isset($seen[$key])) {
            throw new InvalidArgumentException("Two {$kind}s have the key [{$key}].");
        }

        $seen[$key] = true;
    }
}
