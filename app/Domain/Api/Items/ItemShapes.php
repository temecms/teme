<?php

declare(strict_types=1);

namespace App\Domain\Api\Items;

use App\Domain\Addresses\Addresses;
use App\Domain\Api\Envelope;
use App\Domain\Api\Live;
use App\Domain\Api\Shapes\AuthorShape;
use App\Domain\Api\Shapes\Dates;
use App\Domain\Api\Shapes\MediaShape;
use App\Domain\Api\Shapes\TermShape;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Fields\BuiltInFields;
use App\Domain\People\Credits;
use App\Domain\Site\SiteLanguage;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * An item's summary as the API gives it (D6), and the parts every item of a
 * page shares a statement for (D33): their terms, their authors and their
 * featured images, one statement each, whatever the page's size.
 */
final readonly class ItemShapes
{
    /**
     * What an item query selects for a summary, beside the published fields
     * and Live::withLocked()'s `locked`.
     */
    public const array COLUMNS = [
        'contents.id', 'contents.type', 'contents.slug', 'contents.language', 'contents.author_id', 'contents.visibility', 'contents.published_at', 'contents.parent_id', 'contents.position',
        'revision.title', 'revision.created_at as revised_at', 'paths.path',
    ];

    public function __construct(private CardMedia $media, private SiteLanguage $language, private TaxonomyRegistry $taxonomies) {}

    /**
     * The terms, authors and featured images of these rows, read in three
     * statements and noted on the page as each is shown (D18), and which of
     * their parents are live, in one more when any has a parent (I-3363).
     *
     * @param  list<object>  $rows
     *
     * @return array{terms: array<string, list<object>>, authors: array<string, list<object>>, images: array<string, array<string, mixed>>, parents: array<string, true>}
     */
    public function parts(array $rows): array
    {
        if ($rows === []) {
            return ['terms' => [], 'authors' => [], 'images' => [], 'parents' => []];
        }

        app(RenderRecord::class)->tag(...array_map(fn (object $row): string => PageTags::content((string) $row->id), $rows));

        return ['terms' => $this->terms($rows), 'authors' => $this->authors($rows), 'images' => $this->images($rows), 'parents' => $this->parents($rows)];
    }

    /**
     * The item as a list gives it: D6's summary, its own link included.
     *
     * @param  array{terms: array<string, list<object>>, authors: array<string, list<object>>, images: array<string, array<string, mixed>>, parents: array<string, true>}  $parts
     *
     * @return array<string, mixed>
     */
    public function summary(object $row, array $parts): array
    {
        return [...$this->item($row, $parts), 'links' => ['self' => Envelope::link('api.v1.items.show', ['id' => (string) $row->id])]];
    }

    /**
     * D6's summary without its links, as the full item opens.
     *
     * @param  array{terms: array<string, list<object>>, authors: array<string, list<object>>, images: array<string, array<string, mixed>>, parents: array<string, true>}  $parts
     *
     * @return array<string, mixed>
     */
    public function item(object $row, array $parts): array
    {
        $id = (string) $row->id;
        $type = (string) $row->type;
        $path = $row->path === null ? null : Addresses::toUrl((string) $row->path, $type);
        $locked = (bool) $row->locked;
        $fields = $locked ? [] : self::fields($row);
        $excerpt = $locked ? '' : trim(is_string($fields[BuiltInFields::EXCERPT] ?? null) ? $fields[BuiltInFields::EXCERPT] : '');
        // Who it credits, in order: its bylines, or its owner with none (I-3366).
        $authors = array_map(fn (object $person): array => AuthorShape::of($person, $person->path), $parts['authors'][$id] ?? []);
        $published = Dates::utc((string) $row->published_at);
        $revised = Dates::utc((string) $row->revised_at);

        return [
            'id' => $id,
            'type' => $type,
            'title' => (string) $row->title,
            'slug' => (string) $row->slug,
            'path' => $path,
            'url' => $path === null ? null : rtrim(url('/'), '/').$path,
            // A hidden parent answers exactly as no parent (I-3363).
            'parent_id' => $row->parent_id !== null && isset($parts['parents'][(string) $row->parent_id]) ? (string) $row->parent_id : null,
            'visibility' => (string) $row->visibility,
            'language' => $this->language->of($row->language),
            'published_at' => $published,
            // As the sitemap's changed_at: the later of going live and the revision's making.
            'updated_at' => max($published, $revised),
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'featured_image' => $locked ? null : $this->featured($fields, $parts['images']),
            'author' => $authors[0] ?? null,
            'authors' => $authors,
            'terms' => $this->termsOf($type, $parts['terms'][$id] ?? []),
        ];
    }

    /**
     * The row's published fields, as stored.
     *
     * @return array<string, mixed>
     */
    public static function fields(object $row): array
    {
        return (array) json_decode((string) ($row->fields ?? '{}'), true);
    }

    /**
     * The featured image with this use's alt text, or null when unset, in
     * the trash or missing.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $images
     *
     * @return array<string, mixed>|null
     */
    private function featured(array $fields, array $images): ?array
    {
        $value = $fields[BuiltInFields::FEATURED_IMAGE] ?? null;
        $id = is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : '';

        if (! isset($images[$id])) {
            return null;
        }

        $alt = is_string($value['alt'] ?? null) && trim($value['alt']) !== '' ? $value['alt'] : null;

        return MediaShape::of(['id' => $id, ...$images[$id]], $alt, (bool) ($value['decorative'] ?? false));
    }

    /**
     * The item's terms keyed by each public taxonomy its type uses, in the
     * type's order, each list in the revision's order; `{}` for none.
     *
     * @param  list<object>  $terms
     *
     * @return array<string, list<array<string, mixed>>>|stdClass
     */
    private function termsOf(string $type, array $terms): array|stdClass
    {
        $keyed = [];

        foreach ($this->publicTaxonomies($type) as $taxonomy) {
            $keyed[$taxonomy] = [];
        }

        foreach ($terms as $term) {
            if (array_key_exists((string) $term->taxonomy, $keyed)) {
                $keyed[(string) $term->taxonomy][] = TermShape::link($term, $term->path === null ? null : (string) $term->path);
            }
        }

        return $keyed === [] ? new stdClass : $keyed;
    }

    /**
     * The public taxonomies a type uses, in its order.
     *
     * @return list<string>
     */
    private function publicTaxonomies(string $type): array
    {
        $public = Live::taxonomies();

        return array_values(array_filter(
            array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, $this->taxonomies->forType($type)),
            fn (string $key): bool => in_array($key, $public, true),
        ));
    }

    /**
     * Each row's published terms in its public taxonomies, with their current
     * addresses, in the revision's order; one statement.
     *
     * @param  list<object>  $rows
     *
     * @return array<string, list<object>>
     */
    private function terms(array $rows): array
    {
        $taxonomies = array_values(array_unique(array_merge(...array_map(fn (object $row): array => $this->publicTaxonomies((string) $row->type), $rows))));

        if ($taxonomies === []) {
            return [];
        }

        $byItem = [];

        foreach (DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->leftJoin('paths', fn (JoinClause $join) => $join->on('paths.target_id', '=', DB::raw('terms.id::text'))->where('paths.target_type', 'term')->where('paths.is_current', true))
            ->whereIn('content_terms.content_id', array_map(fn (object $row): string => (string) $row->id, $rows))
            ->where('content_terms.scope', 'published')
            ->whereIn('terms.taxonomy', $taxonomies)
            ->orderBy('content_terms.content_id')
            ->orderBy('content_terms.position')
            ->get(['content_terms.content_id', 'terms.id', 'terms.taxonomy', 'terms.name', 'terms.slug', 'paths.path']) as $term) {
            $byItem[(string) $term->content_id][] = $term;
            app(RenderRecord::class)->tag(PageTags::term((string) $term->id));
        }

        return $byItem;
    }

    /**
     * Which of the rows' parents are live, by ID; one statement, none when
     * no row has a parent. Records each parent's tag, live or not, so a
     * stored answer goes when one is hidden, shown or moved.
     *
     * @param  list<object>  $rows
     *
     * @return array<string, true>
     */
    private function parents(array $rows): array
    {
        $ids = array_values(array_unique(array_map(strval(...), array_filter(array_map(fn (object $row): mixed => $row->parent_id ?? null, $rows), fn (mixed $id): bool => $id !== null))));

        if ($ids === []) {
            return [];
        }

        app(RenderRecord::class)->tag(...array_map(PageTags::content(...), $ids));

        return array_fill_keys(array_map(strval(...), Live::items()->whereIn('contents.id', $ids)->pluck('contents.id')->all()), true);
    }

    /**
     * The rows' credits, by content ID, in order, as their author pages show
     * them: name, slug, bio and address only, never an email or a role; one
     * statement (Credits::shown()).
     *
     * @param  list<object>  $rows
     *
     * @return array<string, list<object>>
     */
    private function authors(array $rows): array
    {
        return app(Credits::class)->shown(array_map(fn (object $row): string => (string) $row->id, $rows));
    }

    /**
     * The featured images of the rows a reader may see the picture of, in
     * one statement; a file in the trash or missing is left out.
     *
     * @param  list<object>  $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private function images(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $value = $row->locked ? null : (self::fields($row)[BuiltInFields::FEATURED_IMAGE] ?? null);

            if (is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
                $ids[] = $value['id'];
            }
        }

        return $this->media->forIds($ids);
    }
}
