<?php

/**
 * The old site's Polylang languages for an import (I-3446): read from the
 * companion's `languages` route by the first step of a run, and kept in the
 * cache six hours, as the site's analysis is (I-261: the cache is
 * disposable), so each later job asks the old site at most once. Turns an
 * item's Polylang slug into the language it is stored in here.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Translations;

use App\Domain\Import\ImportEngine;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Support\Network\AddressRefused;
use Illuminate\Contracts\Cache\Repository;

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

            $languages = PolylangLanguages::unanswered();
        }

        $this->cache->put(self::key($sourceId), $languages->toArray(), self::KEEP_SECONDS);

        return $languages;
    }

    /**
     * Returns the source's languages: the answer kept, else read afresh
     * from the old site and kept. A site that can't be read now is never
     * taken for one without Polylang: the refusal reaches the step, which
     * waits for the site or stops as any other read does.
     *
     * @throws SourceRefused|AddressRefused when the answer isn't kept and the site can't be read now
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

        return $this->refresh($sourceId, $this->clients->for($source));
    }

    /**
     * The source's languages for a phase that imports one object at a time:
     * a site that can't be read now stops the run, which carries on later,
     * so no object is written in the wrong language.
     *
     * @throws ImportStopped when the answer isn't kept and the site can't be read now
     */
    public function forObjects(string $sourceId): PolylangLanguages
    {
        try {
            return $this->for($sourceId);
        } catch (SourceRefused|AddressRefused $refused) {
            throw ImportStopped::because($refused->reason, $refused->sentence().ImportEngine::AGAIN);
        }
    }

    /**
     * The language an item or term of this Polylang slug is stored in here,
     * as `language` holds it: null for the default language, for a site
     * without Polylang, and for a type or taxonomy this site doesn't
     * translate; false when the slug names a language this site lacks.
     *
     * @throws ImportStopped when the answer isn't kept and the site can't be read now
     */
    public function stored(string $sourceId, mixed $slug, bool $translated): string|false|null
    {
        $languages = $this->forObjects($sourceId);

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
