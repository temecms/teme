<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

/**
 * A stored path read as a feed address (I-299): WordPress's spellings, which
 * are the base, then `feed`, or `feed/{name}`, or a bare `{name}`, with the
 * names feed, rdf, rss, rss2 and atom. Says what the base is and whether the
 * path is already the one canonical spelling, `{base}/feed`.
 */
final readonly class FeedPath
{
    /** The names WordPress gives a feed (`$wp_rewrite->feeds`). */
    public const array NAMES = ['feed', 'rdf', 'rss', 'rss2', 'atom'];

    /**
     * The route's pattern for the whole path, in any case: a last segment
     * that is a name, or `feed/` and a name. The controller reads the path
     * itself with parse(); this only decides which requests reach it.
     */
    public const string ROUTE = '(?i:(?:.+/)?(?:feed|rdf|rss|rss2|atom)(?:/(?:feed|rdf|rss|rss2|atom))?)';

    public function __construct(public string $base, public bool $canonical) {}

    /**
     * The path as a feed address, or null when its last segment is no feed name.
     */
    public static function parse(string $path): ?self
    {
        $segments = $path === '' ? [] : explode('/', $path);
        $last = array_pop($segments);

        if ($last === null || ! in_array($last, self::NAMES, true)) {
            return null;
        }

        // `feed/{name}`: the long form, never the canonical one.
        $previous = $segments === [] ? null : $segments[array_key_last($segments)];

        if ($previous === 'feed') {
            array_pop($segments);

            return new self(implode('/', $segments), false);
        }

        return new self(implode('/', $segments), $last === 'feed');
    }

    /**
     * The canonical site-relative address of this base's feed: always with a trailing slash, as WordPress's are.
     */
    public function url(): string
    {
        return $this->base === '' ? '/feed/' : '/'.$this->base.'/feed/';
    }
}
