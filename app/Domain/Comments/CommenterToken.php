<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * A browser's own key (docs/studies/comments.md §3.4, I-276): made by core's
 * script at the browser's first comment and kept there, sent with each
 * comment and with the question for the browser's own; only its hash is
 * stored, on the comment, and only the hash is ever compared.
 */
final class CommenterToken
{
    /** What a key looks like: base64url, 22 to 128 characters; core's script makes 32. */
    public const string PATTERN = '/^[A-Za-z0-9_-]{22,128}$/';

    /**
     * Whether the text is a key at all, read before anything else is.
     */
    public static function valid(string $token): bool
    {
        return preg_match(self::PATTERN, $token) === 1;
    }

    /**
     * The key as a comment keeps it.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
