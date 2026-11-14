<?php

declare(strict_types=1);

namespace App\Domain\Comments;

/**
 * The comment rate limit (docs/studies/comments.md §3.4, Emdash's figure):
 * five comments in ten minutes from one IP address, and five from one email
 * address, counted by Laravel's rate limiter in the cache store, as
 * sign-in's are.
 */
final class CommentThrottle
{
    /** Comments one IP address, or one email address, may send in DECAY seconds. */
    public const int MAX = 5;

    /** The window the comments are counted in, in seconds. */
    public const int DECAY = 600;

    /**
     * The rate limiter's keys for a comment from this IP address and this
     * email address, for those given: the address as typed for the IP, and a
     * hash of the email address, so no key holds it.
     *
     * @return list<string>
     */
    public static function keys(?string $ip, ?string $email): array
    {
        $keys = [];

        if ($ip !== null && $ip !== '') {
            $keys[] = 'comments:ip:'.$ip;
        }

        if ($email !== null && $email !== '') {
            $keys[] = 'comments:email:'.sha1($email);
        }

        return $keys;
    }
}
