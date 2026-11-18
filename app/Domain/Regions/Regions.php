<?php

/**
 * The published documents of the regions one page draws, each read once, so
 * a region drawn twice costs one query. It is scoped to a request, and
 * start() empties it for each page, as one application may serve several
 * (B-227), as SlotMenus does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Types\CoreTypes;
use Illuminate\Support\Facades\DB;

final class Regions
{
    /** @var array<string, array<mixed>|null> */
    private array $documents = [];

    /**
     * Empties it, for a page about to be drawn.
     */
    public function start(): void
    {
        $this->documents = [];
    }

    /**
     * The region's published document, or null when it has none: no row,
     * never published, unpublished since, or in the trash. The page
     * language's version, else the default language's (I-3360).
     *
     * @return array<mixed>|null
     */
    public function published(string $themeCode, string $region): ?array
    {
        $slug = RegionDeclarations::slug($themeCode, $region);

        // Drawn or empty, the page shows what this region holds (I-263).
        app(RenderRecord::class)->tag(PageTags::region($slug));

        $language = app(PageLanguage::class)->stored();
        $key = $slug.'|'.$language;

        if (array_key_exists($key, $this->documents)) {
            return $this->documents[$key];
        }

        // The page language's version, else the default language's (I-3360).
        $versions = DB::table('contents');
        $versions = $language === null
            ? app(SiteLanguages::class)->whereDefault($versions, 'contents.language')
            : $versions->where(fn ($query) => $query->whereNull('contents.language')->orWhere('contents.language', $language));

        $document = $versions
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->where('contents.type', CoreTypes::REGION)
            ->where('contents.slug', $slug)
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->orderByRaw('contents.language IS NULL')
            ->value('content_revisions.document');

        return $this->documents[$key] = $document === null ? null : (array) json_decode((string) $document, true);
    }
}
