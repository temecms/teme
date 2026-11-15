<?php

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Planning\ImportAnalysis;
use Illuminate\Contracts\Cache\Repository;

/**
 * Hosts the companion's analysis lists as linked from the site that look
 * like other addresses of this site (a subdomain of the home host), for the
 * person to tick before a run (docs/proposals/import-v1-fixes.md). Kept from
 * the analysis read, so the import page never asks the old site twice.
 */
final readonly class SuggestedHosts
{
    public function __construct(private Repository $cache) {}

    /**
     * The cache key a source's linked hosts are kept under.
     */
    public static function key(string $sourceId): string
    {
        return 'import.suggested-hosts.'.$sourceId;
    }

    /**
     * Keeps the analysis's linked hosts for this source, as long as the
     * analysis itself.
     *
     * @param  array<mixed>|null  $report
     */
    public function remember(string $sourceId, ?array $report): void
    {
        $sections = is_array($report) && is_array($report['sections'] ?? null) ? $report['sections'] : [];
        $content = is_array($sections['content'] ?? null) ? $sections['content'] : [];
        $html = is_array($content['html'] ?? null) ? $content['html'] : [];
        $links = is_array($html['links'] ?? null) ? $html['links'] : [];
        $external = is_array($links['external_hosts'] ?? null) ? $links['external_hosts'] : [];

        $hosts = [];

        foreach ($external as $host => $count) {
            $bare = OldSiteUrls::bare((string) $host);
            $linksCount = is_numeric($count) ? (int) $count : 0;

            if ($bare !== '' && $linksCount > 0) {
                $hosts[$bare] = $linksCount;
            }
        }

        $this->cache->put(self::key($sourceId), $hosts, ImportAnalysis::KEEP_SECONDS);
    }

    /**
     * Forgets the kept hosts, so the next analysis read fills them again.
     */
    public function forget(string $sourceId): void
    {
        $this->cache->forget(self::key($sourceId));
    }

    /**
     * Suggested hosts with their link counts, most links first; never a host
     * already listed on the source, never another domain, and none when the
     * analysis has no such section (older companion) or has not been read.
     *
     * @return list<array{host: string, links: int}>
     */
    public function for(ImportSource $source): array
    {
        $home = OldSiteUrls::bare((string) parse_url($source->address, PHP_URL_HOST));
        $listed = array_values(array_filter((array) ($source->other_hosts ?? []), is_string(...)));
        $kept = $this->cache->get(self::key((string) $source->id));
        $hosts = is_array($kept) ? array_map(intval(...), $kept) : [];

        $suggestions = [];

        foreach ($hosts as $host => $linksCount) {
            $host = (string) $host;

            if ($linksCount < 1 || $host === $home || in_array($host, $listed, true)) {
                continue;
            }

            if (! str_ends_with($host, '.'.$home)) {
                continue;
            }

            $suggestions[] = ['host' => $host, 'links' => $linksCount];
        }

        usort($suggestions, fn (array $a, array $b): int => [$b['links'], $a['host']] <=> [$a['links'], $b['host']]);

        return $suggestions;
    }
}
