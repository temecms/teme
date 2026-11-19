<?php

/**
 * The old site's addresses as the converter needs them: its home, from the
 * source; where WordPress keeps its uploads and which shortcodes were
 * registered at export, from the companion's analyze report, kept in the
 * cache six hours as the analysis is (I-261: the cache is disposable).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Support\Network\AddressRefused;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;

final readonly class ContentSite
{
    /** How long the report's answer is kept: as long as Tools › Import keeps the analysis. */
    public const int KEEP_SECONDS = 21600;

    public function __construct(private ImportSources $sources, private CompanionClients $clients, private Repository $cache) {}

    /**
     * The cache key a source's answer is kept under.
     */
    public static function key(string $sourceId): string
    {
        return 'import.content-site.'.$sourceId;
    }

    /**
     * Returns the old site's addresses for the converter: the defaults
     * (uploads below the home, no registered shortcodes) when the report
     * can't be read, which isn't kept, so the next page job asks again.
     */
    public function for(string $sourceId): ConversionSite
    {
        $source = $this->sources->find($sourceId) ?? throw new InvalidArgumentException('Teme has no import source ['.$sourceId.'].');
        $kept = $this->cache->get(self::key($sourceId));

        if (! is_array($kept)) {
            $kept = $this->read($source);

            if ($kept !== null) {
                $this->cache->put(self::key($sourceId), $kept, self::KEEP_SECONDS);
            }
        }

        $uploads = $kept['uploads'] ?? null;
        $shortcodes = array_values(array_filter((array) ($kept['shortcodes'] ?? []), is_string(...)));
        $otherHosts = array_values(array_filter((array) ($source->other_hosts ?? []), is_string(...)));

        return new ConversionSite($source->address, is_string($uploads) ? $uploads : null, $shortcodes, $otherHosts);
    }

    /**
     * The report's uploads folder and registered shortcodes; the defaults
     * for a site with no report; null when the site couldn't be read.
     *
     * @return array{uploads: string|null, shortcodes: list<string>}|null
     */
    private function read(ImportSource $source): ?array
    {
        try {
            $report = $this->clients->for($source)->whole('analyze')['report'] ?? null;
        } catch (SourceRefused|AddressRefused) {
            return null;
        }

        $sections = is_array($report) && is_array($report['sections'] ?? null) ? $report['sections'] : [];
        $media = is_array($sections['media'] ?? null) ? $sections['media'] : [];
        $uploads = is_array($media['uploads'] ?? null) ? $media['uploads'] : [];
        $path = is_string($uploads['baseurl_path'] ?? null) ? trim($uploads['baseurl_path'], '/') : '';
        $host = is_string($uploads['uploads_host'] ?? null) ? strtolower(trim($uploads['uploads_host'])) : '';
        $home = parse_url($source->address);
        $scheme = is_array($home) && is_string($home['scheme'] ?? null) ? $home['scheme'] : 'https';
        $hostOrHome = $host !== '' ? $host : (is_array($home) && is_string($home['host'] ?? null) ? $home['host'] : '');

        $content = is_array($sections['content'] ?? null) ? $sections['content'] : [];
        $codes = is_array($content['shortcodes'] ?? null) ? $content['shortcodes'] : [];
        $names = [];

        foreach (is_array($codes['tags'] ?? null) ? $codes['tags'] : [] as $name => $tag) {
            if (is_array($tag) && ($tag['registered'] ?? null) === true) {
                $names[] = strtolower((string) $name);
            }
        }

        return [
            'uploads' => $path === '' || $hostOrHome === '' ? null : "{$scheme}://{$hostOrHome}/{$path}",
            'shortcodes' => $names,
        ];
    }
}
