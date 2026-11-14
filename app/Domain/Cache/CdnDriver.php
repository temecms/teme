<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use Symfony\Component\HttpFoundation\Response;

/**
 * A CDN in front of the site that the page cache purges (spec §5.6,
 * I-514): what a stored page tells it, and how Teme purges it: by key, by
 * address for a page the web server served as a file (slice 1.7d″), or
 * whole. Cloudflare's is the first; another CDN is one more class, named in
 * configuration (`page_cache.cdn.driver`, CdnPurges::driver()).
 */
interface CdnDriver
{
    /**
     * Tells the CDN, on a stored page's response, the keys the page carries
     * and the seconds it may keep it, in headers the CDN reads and strips
     * before the reader. Sets nothing when the keys don't fit the CDN's
     * limit: a purge of a key left out would miss the page, so the CDN keeps
     * it no longer than Cache-Control lets any shared cache.
     *
     * @param  list<string>  $keys
     */
    public function describe(Response $response, array $keys, int $seconds): void;

    /**
     * Purges, at the CDN, every page carrying any of these keys, at most
     * batchSize() of them.
     *
     * @param  list<string>  $keys
     *
     * @throws CdnBusy when the CDN asks Teme to wait before it purges again
     */
    public function purgeKeys(array $keys): void;

    /**
     * Purges, at the CDN, the pages at these full addresses, at most
     * batchSize() of them: a page the web server served as a file carries
     * none of the keys describe() sets (D10).
     *
     * @param  list<string>  $urls
     *
     * @throws CdnBusy when the CDN asks Teme to wait before it purges again
     */
    public function purgeUrls(array $urls): void;

    /**
     * Purges every page of the site at the CDN.
     *
     * @throws CdnBusy when the CDN asks Teme to wait before it purges again
     */
    public function purgeSite(): void;

    /**
     * How many keys, or addresses, one purge request may carry.
     */
    public function batchSize(): int;

    /**
     * How many purge requests a minute the CDN takes from this site by key or by host.
     */
    public function perMinute(): int;

    /**
     * How many addresses a second the CDN purges for this site: a budget of
     * their own, apart from perMinute()'s.
     */
    public function addressesPerSecond(): int;
}
