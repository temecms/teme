<?php

declare(strict_types=1);

namespace App\Domain\Import\Translations;

use App\Domain\Import\Queries\ImportSources;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Support\Network\AddressRefused;
use Illuminate\Contracts\Cache\Repository;

/**
 * The old site's Polylang languages for an import (I-3446): read from the
 * companion's `languages` route by the first step of a run, and kept in the
 * cache six hours, as the site's analysis is (I-261: the cache is
 * disposable), so each later job asks the old site at most once. Turns an
 * item's Polylang slug into the language it is stored in here.
 */
final readonly class ImportedLanguages
{
    /** How long the answer is kept: as long as Tools › Import keeps the analysis. */
    public const int KEEP_SECONDS = 21600;

    public function __construct(private ImportSources $sources, private CompanionClients $clients, private Repository $cache) {}

    /**
     * The cache key a source's answer is kept under.
     */
    public static function key(string $sourceId): string
    {
        return 'import.languages.'.$sourceId;
    }

    /**
     * Reads the old site's answer afresh through the run's reader and keeps
     * it: a site whose companion has no `languages` route reads as a site
     * without Polylang.
     *
     * @throws SourceRefused for any other refusal
     */
    public function refresh(string $sourceId, SourceReader $reader): PolylangLanguages
    {
        try {
            $languages = PolylangLanguages::fromAnswer($reader->whole('languages'));
        } catch (SourceRefused $refused) {
            if ($refused->reason !== SourceRefused::NOT_COMPANION) {
                throw $refused;
            }

            $languages = PolylangLanguages::none();
        }

        $this->cache->put(self::key($sourceId), $languages->toArray(), self::KEEP_SECONDS);

        return $languages;
    }

    /**
     * Returns the source's languages: the answer kept, else read afresh
     * from the old site; a site that can't be read now reads as one without
     * Polylang, and isn't kept, so the next job asks again.
     */
    public function for(string $sourceId): PolylangLanguages
    {
        $kept = $this->cache->get(self::key($sourceId));

        if (is_array($kept)) {
            return PolylangLanguages::fromAnswer($kept);
        }

        $source = $this->sources->find($sourceId);

        if ($source === null) {
            return PolylangLanguages::none();
        }

        try {
            return $this->refresh($sourceId, $this->clients->for($source));
        } catch (SourceRefused|AddressRefused) {
            return PolylangLanguages::none();
        }
    }

    /**
     * The language an item or term of this Polylang slug is stored in here,
     * as `language` holds it: null for the default language, for a site
     * without Polylang, and for a type or taxonomy this site doesn't
     * translate; false when the slug names a language this site lacks.
     */
    public function stored(string $sourceId, mixed $slug, bool $translated): string|false|null
    {
        $languages = $this->for($sourceId);

        if (! $languages->active || ! $translated || ! is_string($slug) || $slug === '' || $slug === $languages->default) {
            return null;
        }

        $tag = $languages->language($slug)['language'] ?? null;
        $site = app(SiteLanguages::class);

        if ($tag === null || ! $site->has($tag)) {
            return false;
        }

        return $site->stored($tag);
    }
}
