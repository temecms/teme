<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

/**
 * Makes the addresses in a feed absolute from the site's own address as the
 * request names it, when the feed is drawn and never before (rule 9): a
 * feed reader has no page to resolve a relative address against. It holds
 * nothing.
 */
final class FeedUrls
{
    /**
     * The site's address with no trailing slash.
     */
    public function origin(): string
    {
        return rtrim(url('/'), '/');
    }

    /**
     * The address as an absolute one: a site-relative `/path` gets the
     * site's address, a `//host/path` its scheme, and anything with a scheme
     * of its own stays as it is.
     */
    public function absolute(string $url): string
    {
        return match (true) {
            preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1 => $url,
            str_starts_with($url, '//') => ((string) parse_url($this->origin(), PHP_URL_SCHEME)).':'.$url,
            str_starts_with($url, '/') => $this->origin().$url,
            default => $url,
        };
    }
}
