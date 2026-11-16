<?php

declare(strict_types=1);

namespace App\Domain\Import\Planning;

use App\Domain\Import\Queries\ImportSources;
use App\Domain\Import\Queries\SuggestedHosts;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Import\Translations\LanguageNotes;
use App\Domain\Support\Network\AddressRefused;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;

/**
 * A site's analysis, read from its Teme Companion's site report and kept in
 * the cache for six hours, so the analyze and choices steps ask the old site
 * once (I-261: the cache is disposable, and the summary holds no personal
 * data). A refusal is never kept.
 */
final readonly class ImportAnalysis
{
    /** How long a site's analysis is kept: six hours. */
    public const int KEEP_SECONDS = 21600;

    public function __construct(
        private ImportSources $sources,
        private CompanionClients $clients,
        private Repository $cache,
        private SuggestedHosts $suggestedHosts,
    ) {}

    /**
     * The cache key a site's analysis is kept under.
     */
    public static function key(string $sourceId): string
    {
        return 'import.analysis.'.$sourceId;
    }

    /**
     * Returns the site's analysis: the one kept, or its report read afresh
     * and kept.
     *
     * @throws SourceRefused|AddressRefused when the site can't be read
     */
    public function read(string $sourceId): SiteAnalysis
    {
        $kept = $this->kept($sourceId);

        if ($kept !== null) {
            return $kept;
        }

        $source = $this->sources->find($sourceId) ?? throw new InvalidArgumentException('Teme has no import source ['.$sourceId.'].');
        $reader = $this->clients->for($source);
        $report = $reader->whole('analyze')['report'] ?? null;
        $analysis = is_array($report) ? SiteAnalysis::fromReport($report) : SiteAnalysis::noReport();
        // What the import does with the site's languages (I-3446).
        $analysis = $analysis->withStays(LanguageNotes::stays(app(ImportedLanguages::class)->refresh($sourceId, $reader)));

        $this->cache->put(self::key($sourceId), $analysis->toArray(), self::KEEP_SECONDS);
        $this->suggestedHosts->remember($sourceId, is_array($report) ? $report : null);

        return $analysis;
    }

    /**
     * Returns the analysis kept for the site, or null when none is kept. It
     * never asks the site.
     */
    public function kept(string $sourceId): ?SiteAnalysis
    {
        $stored = $this->cache->get(self::key($sourceId));

        return is_array($stored) ? SiteAnalysis::fromArray($stored) : null;
    }

    /**
     * Forgets the kept analysis, so the next read asks the site.
     */
    public function forget(string $sourceId): void
    {
        $this->cache->forget(self::key($sourceId));
        $this->suggestedHosts->forget($sourceId);
    }
}
