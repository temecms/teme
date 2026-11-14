<?php

declare(strict_types=1);

namespace App\Domain\Api\Items;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Api\Live;
use App\Domain\Api\Shapes\ItemLinkShape;
use App\Domain\Api\Ulids;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Tree\ContentTree;
use App\Domain\Site\SiteLanguage;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One live, public item in full (D6, D34): found by its ID or by an address
 * of it, current or old, and shaped from its published revision only, with
 * its fields and its body; a protected item without its words (D30).
 */
final readonly class ItemQuery
{
    public function __construct(private ItemShapes $shapes, private FieldValues $fields, private BodyShape $body, private SiteLanguage $language) {}

    /**
     * The item's row, from its published revision with its document, fields
     * and word count (the document and fields null for a locked item, never
     * read), or null when no live, public item has this ID. The ID is noted
     * on the page found or not, so its publication purges the 404 (D18).
     */
    public function byId(string $id): ?object
    {
        if (preg_match(Ulids::LOWER, $id) !== 1) {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::content($id));

        return Live::unlessLocked(Live::withLocked(Live::items()), 'revision.fields', 'revision.document')
            ->join('content_revisions as revision', 'revision.id', '=', 'contents.published_revision_id')
            // Compared as text, as ListingViews::itemsById() does, so the paths index answers the join.
            ->leftJoin('paths', fn (JoinClause $join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->where('contents.id', $id)
            ->addSelect([...ItemShapes::COLUMNS, 'revision.word_count'])
            ->first();
    }

    /**
     * The item at this address, as the query string gave it once decoded,
     * and whether the address is an old one, or
     * null when no live, public item has it (D29): the page shown as the home
     * page at `/`, an item at its current address, or the item an old
     * address of it led to. The asked address is noted on the page found or
     * not, so a publication there purges the 404 (D18).
     *
     * @return array{row: object, moved: bool}|null
     */
    public function byPath(string $path): ?array
    {
        app(RenderRecord::class)->tag(PageTags::url($path));

        // The query string was decoded once already (ApiQuery); normalise()
        // decodes again, so each % is kept as itself rather than read twice.
        $normalised = PublicRequestPath::normalise(str_replace('%', '%25', $path));
        $settings = AddressSettings::current();

        if ($normalised === '' && $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId !== '') {
            $row = $this->byId($settings->frontPageId);

            return $row === null ? null : ['row' => $row, 'moved' => false];
        }

        $found = Paths::find($normalised);

        // An old address under a type's archive page is the archive's now (I-3304), as on the site.
        if ($found === null || $found->target_type !== 'content' || (! $found->is_current && TypeArchives::at($found->path) !== null)) {
            return null;
        }

        $row = $this->byId((string) $found->target_id);

        return $row === null ? null : ['row' => $row, 'moved' => ! $found->is_current];
    }

    /**
     * The full item (D6): the summary without its links, its live
     * ancestors, then its fields shown on the site, its word count and its
     * body, each null or `{}` for a protected item, whose words are never
     * read.
     *
     * @return array<string, mixed>
     */
    public function full(object $row): array
    {
        $item = [...$this->shapes->item($row, $this->shapes->parts([$row])), 'ancestors' => $this->ancestors($row)];

        if ((bool) $row->locked) {
            return [...$item, 'fields' => new stdClass, 'word_count' => null, 'body' => null];
        }

        $document = (array) json_decode((string) $row->document, true);

        return [
            ...$item,
            'fields' => $this->fields->for((string) $row->type, ItemShapes::fields($row)),
            'word_count' => (int) $row->word_count,
            'body' => $this->body->of($document),
        ];
    }

    /**
     * The item's live ancestors, the top first, each as an entry field names
     * an item, by its published title; [] at the top and for a type that
     * doesn't nest (I-3363). One bounded statement for the IDs, one for the
     * live ones; none for an item with no parent.
     *
     * @return list<array{id: string, type: string, language: string, title: string, path: string, url: string}>
     */
    private function ancestors(object $row): array
    {
        if ($row->parent_id === null) {
            return [];
        }

        $depths = [];

        foreach (DB::select(<<<'SQL'
            WITH RECURSIVE up AS (
                SELECT c.id, c.parent_id, 0 AS depth FROM contents c WHERE c.id = ?
                UNION ALL
                SELECT p.id, p.parent_id, up.depth + 1
                FROM up CROSS JOIN LATERAL (SELECT id, parent_id FROM contents WHERE contents.id = up.parent_id LIMIT 1) p
                WHERE up.depth < ?
            )
            SELECT id, depth FROM up WHERE depth > 0
            SQL, [(string) $row->id, ContentTree::MOST_LEVELS - 1]) as $up) {
            $depths[(string) $up->id] = (int) $up->depth;
        }

        $live = Live::items()
            ->join('content_revisions as revision', 'revision.id', '=', 'contents.published_revision_id')
            ->join('paths', fn (JoinClause $join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->whereIn('contents.id', array_keys($depths))
            ->get(['contents.id', 'contents.type', 'contents.language', 'revision.title', 'paths.path'])
            ->sortByDesc(fn (object $ancestor): int => $depths[(string) $ancestor->id])
            ->values()
            ->all();

        app(RenderRecord::class)->tag(...array_map(fn (object $ancestor): string => PageTags::content((string) $ancestor->id), $live));

        return array_map(fn (object $ancestor): array => ItemLinkShape::of((string) $ancestor->id, (string) $ancestor->type, $this->language->of($ancestor->language), (string) $ancestor->title, (string) $ancestor->path), $live);
    }
}
