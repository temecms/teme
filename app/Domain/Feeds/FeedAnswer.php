<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

/**
 * What a path ending in a feed name means (I-299): a feed of a subject, a permanent redirect to its canonical address, a 410 for a comment feed, or an address that is not a feed's, which the public site answers.
 */
final readonly class FeedAnswer
{
    public const string FEED = 'feed';

    public const string REDIRECT = 'redirect';

    public const string GONE = 'gone';

    public const string OTHER = 'other';

    public function __construct(public string $kind, public ?FeedSubject $subject = null, public ?string $location = null) {}

    /**
     * The feed of this subject.
     */
    public static function feed(FeedSubject $subject): self
    {
        return new self(self::FEED, $subject);
    }

    /**
     * A permanent redirect to this site-relative address.
     */
    public static function redirect(string $location): self
    {
        return new self(self::REDIRECT, null, $location);
    }

    /**
     * A comment feed, which does not exist.
     */
    public static function gone(): self
    {
        return new self(self::GONE);
    }

    /**
     * An address that is not a feed's.
     */
    public static function other(): self
    {
        return new self(self::OTHER);
    }
}
