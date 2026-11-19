<?php

/**
 * Cloudflare in front of the site (I-514). A stored page's keys go in
 * Cache-Tag and the life it has left in Cloudflare-CDN-Cache-Control, both
 * of which Cloudflare reads and strips before the reader; a change is
 * purged by tag, or by address for a page the web server served as a file,
 * through Cloudflare's API, with a token whose one permission is to purge
 * the zone's cache. Purging by tag is on every plan; Free takes five purge
 * requests a minute, a hundred tags each; addresses have a budget of their
 * own, 800 a second on Free.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cache;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

final readonly class CloudflareCdn implements CdnDriver
{
    private const string API = 'https://api.cloudflare.com/client/v4/zones/';

    /** Bytes of a page's Cache-Tag header that Cloudflare reads, in all. */
    private const int KEYS_LENGTH = 16 * 1024;

    /** Tags, hosts or addresses one purge request may carry, on every plan (Enterprise takes 500 addresses). */
    private const int PER_REQUEST = 100;

    /**
     * A purge request's fields, by tag, by host and by address: a field
     * written as a quoted key reads, to NoTypeLiteralsTest, as a type named
     * in code (B-225).
     */
    private const string BY_TAG = 'tags';

    private const string BY_HOST = 'hosts';

    private const string BY_ADDRESS = 'files';

    /**
     * Addresses a second Cloudflare purges on the Free plan, its lowest, a
     * budget apart from the requests tags and hosts share; Pro and Business
     * take 1,500 (OUTSIDE, https://developers.cloudflare.com/cache/how-to/purge-cache/,
     * read 2026-09-29).
     */
    private const int ADDRESSES_PER_SECOND = 800;

    /** Seconds to wait when Cloudflare says to wait but not how long: the Free plan's minute. */
    private const int WAIT = 60;

    public function __construct(
        private string $zone,
        #[SensitiveParameter] private string $token,
        private int $perMinute,
        private string $host,
    ) {
        if ($zone === '' || $token === '') {
            throw new InvalidArgumentException('PAGE_CACHE_CDN=cloudflare needs CLOUDFLARE_ZONE_ID and CLOUDFLARE_API_TOKEN.');
        }
    }

    public function describe(Response $response, array $keys, int $seconds): void
    {
        $value = implode(',', $keys);

        // A key left out would never be purged (D3).
        if (strlen($value) > self::KEYS_LENGTH) {
            return;
        }

        $response->headers->set('Cache-Tag', $value);
        // Read before Cache-Control, and never passed on: Cloudflare alone
        // keeps the page as long as Teme does (D1).
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'max-age='.$seconds);
    }

    public function purgeKeys(array $keys): void
    {
        $this->purge([self::BY_TAG => $keys]);
    }

    public function purgeUrls(array $urls): void
    {
        $this->purge([self::BY_ADDRESS => $urls]);
    }

    /**
     * Purges the site's own host, never the whole zone: another host there
     * keeps its cache (D6).
     */
    public function purgeSite(): void
    {
        $this->purge([self::BY_HOST => [$this->host]]);
    }

    public function batchSize(): int
    {
        return self::PER_REQUEST;
    }

    public function perMinute(): int
    {
        return $this->perMinute;
    }

    public function addressesPerSecond(): int
    {
        return self::ADDRESSES_PER_SECOND;
    }

    /**
     * Sends one purge request, and says in an exception when Cloudflare
     * didn't take it: CdnBusy when it asks Teme to wait, a RuntimeException
     * with Cloudflare's own reason otherwise. It waits five seconds to
     * connect and fifteen for an answer at most: the drain runs on the queue.
     *
     * @param  array<string, list<string>>  $body
     */
    private function purge(array $body): void
    {
        $answer = Http::withToken($this->token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post(self::API.$this->zone.'/purge_cache', $body);

        if ($answer->tooManyRequests()) {
            $after = (int) $answer->header('Retry-After');

            throw new CdnBusy($after > 0 ? $after : self::WAIT);
        }

        if (! $answer->successful() || $answer->json('success') !== true) {
            $reason = $answer->json('errors.0.message');

            throw new RuntimeException(sprintf('Cloudflare refused the purge (HTTP %d): %s', $answer->status(), is_string($reason) ? $reason : 'no reason given'));
        }
    }
}
