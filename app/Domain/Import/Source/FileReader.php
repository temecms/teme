<?php

/**
 * Reads an uploaded export file's rows as the companion's routes answer, so
 * the import's phases can't tell the two apart (docs/architecture/import.md).
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Source;

use App\Domain\Import\File\FileAddresses;
use App\Domain\Import\File\ItemRows;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\WordPressRecords;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An export file read as a source: a list is a keyset page of the file's
 * rows, the routes a file can't hold answer as a site without them would,
 * and a term an earlier import of the same site already brought keeps that
 * import's ID, so both ways share one map row.
 */
final class FileReader implements SourceReader
{
    /** The statuses an import without drafts brings: published items, and the files and blocks they show. */
    private const array PUBLISHED = ['publish', 'inherit'];

    /** The attachment IDs the site's posts' outside pictures wait for, through the map's index of waiting rows (#716). */
    private const string WAITED = "SELECT substr(waiting.key, 3)::bigint FROM import_map AS waiting_map, jsonb_array_elements_text(waiting_map.facts -> 'waiting_media') AS waiting(key)"
        ." WHERE waiting_map.source_id = ? AND (waiting_map.facts -> 'waiting_media') <> '[]'::jsonb AND waiting.key LIKE 'a:%'";

    /** Added to a file's term number that another term holds in the map. */
    private const int APART = 1_000_000_000;

    /** @var array<int, int>|null the ID each of the file's terms has in the map, where it differs from the file's */
    private ?array $aliases = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $terms = null;

    /**
     * @param  array<string, mixed>  $choices  the run's choices, or the defaults before it starts
     */
    public function __construct(
        public readonly string $fileId,
        private readonly string $sourceId,
        private readonly array $choices = [],
    ) {}

    public function page(string $list, array $query, int $afterId, int $limit): SourcePage
    {
        return match ($list) {
            ItemRows::USERS, ItemRows::MEDIA => $this->rows($list, $afterId, $limit, $query),
            ItemRows::COMMENTS => ($this->choices['comments'] ?? true) === false ? new SourcePage([], null) : $this->rows($list, $afterId, $limit, $query),
            ItemRows::TERMS => $this->termPage($afterId, $limit),
            ItemRows::CONTENT => $this->content($query, $afterId, $limit),
            'urls' => $this->urls((string) ($query['kind'] ?? ''), $afterId, $limit),
            default => throw SourceRefused::notCompanion(),
        };
    }

    public function whole(string $list, array $query = []): array
    {
        return match ($list) {
            'analyze' => ['report' => $this->report()],
            'settings' => $this->settings(),
            'menus' => $this->menus(),
            // A reader's languages become the site's; a WordPress file's Polylang terms only warn.
            'languages' => $this->languages(),
            'urls' => ['items' => [['id' => 0, 'kind' => 'home', 'url' => $this->home().'/']]],
            default => throw SourceRefused::notCompanion(),
        };
    }

    /**
     * What a reader's file says of its languages, as the companion's languages
     * route would: active when the file names more than one, each tag a
     * language with its slug as the address prefix. A WordPress file has none.
     *
     * @return array<string, mixed>
     */
    private function languages(): array
    {
        $file = ImportFile::query()->whereKey($this->fileId)->first(['reader']);

        if ($file?->reader === null) {
            return ['active' => false];
        }

        $stored = DB::table('import_file_rows')->where(['file_id' => $this->fileId, 'list' => ItemRows::FACTS, 'object_id' => 0])->value('payload');
        $facts = is_string($stored) ? json_decode($stored, true) : null;
        $facts = is_array($facts) ? $facts : [];
        $tags = array_values(array_filter(array_map(strval(...), is_array($facts['readerLanguages'] ?? null) ? $facts['readerLanguages'] : []), fn (string $tag): bool => $tag !== ''));
        $siteDefault = app(SiteLanguages::class)->defaultTag();
        $default = is_string($facts['readerDefaultLanguage'] ?? null) && $facts['readerDefaultLanguage'] !== ''
            ? (string) $facts['readerDefaultLanguage']
            : $siteDefault;

        if ($tags === [] || $default === '') {
            return ['active' => false];
        }

        if (! in_array($default, $tags, true)) {
            $tags = [$default, ...$tags];
        }

        $offered = (array) config('languages');
        $languages = [];

        foreach ($tags as $index => $tag) {
            $slug = $tag;
            $locale = is_array($offered[$tag] ?? null) ? (string) ($offered[$tag]['og'] ?? str_replace('-', '_', $tag)) : str_replace('-', '_', $tag);
            $name = is_array($offered[$tag] ?? null) ? (string) ($offered[$tag]['label'] ?? $tag) : $tag;
            $languages[] = [
                'slug' => $slug,
                'locale' => $locale,
                'name' => $name,
                'rtl' => is_array($offered[$tag] ?? null) && ($offered[$tag]['direction'] ?? 'ltr') === 'rtl',
                'order' => $index,
            ];
        }

        $carriedTypes = is_array($facts['readerTypes'] ?? null) ? $facts['readerTypes'] : [];
        $carriedTaxonomies = is_array($facts['readerTaxonomies'] ?? null) ? $facts['readerTaxonomies'] : [];
        $postTypes = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($type->wordpress !== null && ($carriedTypes === [] || in_array($type->key, $carriedTypes, true) || in_array($type->wordpress, $carriedTypes, true))) {
                $postTypes[] = $type->wordpress;
            }
        }

        $taxonomies = [];

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            $name = $taxonomy->wordpress ?? $taxonomy->key;

            if ($carriedTaxonomies === [] || in_array($taxonomy->key, $carriedTaxonomies, true) || in_array($name, $carriedTaxonomies, true)) {
                if ($taxonomy->wordpress !== null || in_array($taxonomy->key, $carriedTaxonomies, true)) {
                    $taxonomies[] = $name;
                }
            }
        }

        return [
            'active' => true,
            'default' => $default,
            'languages' => $languages,
            'options' => ['force_lang' => 1, 'hide_default' => true],
            'post_types' => $postTypes,
            'taxonomies' => array_values(array_unique($taxonomies)),
            'menus' => [],
            'strings' => [],
        ];
    }

    /**
     * The file's site report, with each menu given a place of its own to choose,
     * since a file has its menus but not where they sit.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $report = ImportFile::query()->whereKey($this->fileId)->value('report');
        $report = is_string($report) ? json_decode($report, true) : $report;
        $report = is_array($report) ? $report : [];

        foreach ((array) ($report['sections']['menus']['menus'] ?? []) as $menu) {
            if (is_array($menu) && is_numeric($menu['term_id'] ?? null)) {
                $report['sections']['menus']['assigned_locations'][self::menuPlace((int) $menu['term_id'])] = (int) $menu['term_id'];
            }
        }

        return $report;
    }

    /**
     * The key a menu's place is chosen under, for a file's menu.
     */
    public static function menuPlace(int $menuId): string
    {
        return 'menu-'.$menuId;
    }

    /**
     * A keyset page of one list's rows, in ID order: an `afterId` of 0 is the
     * start, so the authors and terms a file names only on its items, whose
     * IDs are below zero, come first. A query's `lane` of `lanes` reads only
     * the rows whose ID leaves that lane over the lanes (#701), so lanes read
     * side by side never share a row, and each is read in ID order. A
     * picture pass's `waited` of `only` reads only the attachments a post's
     * outside pictures wait for by ID, and `without` the others (#716).
     *
     * @param  array<string, scalar>  $query
     *
     * @throws InvalidArgumentException for a lane that isn't one of the lanes
     */
    private function rows(string $list, int $afterId, int $limit, array $query = []): SourcePage
    {
        $lanes = (int) ($query['lanes'] ?? 1);
        $lane = (int) ($query['lane'] ?? 0);

        if ($lanes < 1 || $lanes > 16 || $lane < 0 || $lane >= $lanes) {
            throw new InvalidArgumentException("An import list's lane [{$lane}] of [{$lanes}] isn't one.");
        }

        $items = DB::table('import_file_rows')
            ->where('file_id', $this->fileId)
            ->where('list', $list)
            ->when($afterId !== 0, fn ($query) => $query->where('object_id', '>', $afterId))
            ->when($lanes > 1, fn ($query) => $query->whereRaw('mod(object_id, ?::bigint) = ?::bigint', [$lanes, $lane]))
            ->when(in_array($query['waited'] ?? null, ['only', 'without'], true), fn ($rows) => $rows->whereRaw(
                'object_id '.($query['waited'] === 'only' ? 'IN' : 'NOT IN').' ('.self::WAITED.')',
                [$this->sourceId],
            ))
            ->orderBy('object_id')
            ->limit($limit)
            ->pluck('payload')
            ->map(fn (mixed $payload): array => self::decoded($payload))
            ->all();

        return self::paged($items, $limit);
    }

    /**
     * A page of the file's terms, each under the ID its map row has.
     */
    private function termPage(int $afterId, int $limit): SourcePage
    {
        $after = array_values(array_filter($this->terms(), fn (array $term): bool => $afterId === 0 || (int) $term['id'] > $afterId));

        return self::paged(array_slice($after, 0, $limit), $limit);
    }

    /**
     * Every term of the file, each with its map row's ID, its own kept as
     * `file_term_id`, and its address from the bases chosen, in ID order.
     *
     * @return list<array<string, mixed>>
     */
    private function terms(): array
    {
        if ($this->terms !== null) {
            return $this->terms;
        }

        $rows = DB::table('import_file_rows')->where('file_id', $this->fileId)->where('list', ItemRows::TERMS)->orderBy('object_id')->pluck('payload')
            ->map(fn (mixed $payload): array => self::decoded($payload))->all();
        $links = FileAddresses::termLinks($rows, $this->home(), $this->bases());
        $aliases = $this->aliases();
        $terms = [];

        foreach ($rows as $term) {
            $own = (int) ($term['id'] ?? 0);
            $terms[] = [...$term, 'id' => $aliases[$own] ?? $own, 'file_term_id' => $own, 'link' => $links[$own] ?? null];
        }

        usort($terms, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $this->terms = $terms;
    }

    /**
     * The map ID a file's term takes when its own number is another term's
     * there: far above any WordPress gives, and the same on every read.
     */
    public static function apart(int $fileTermId): int
    {
        return self::APART + $fileTermId;
    }

    /**
     * The ID each of the file's terms has in this site's map where an
     * earlier import brought the same term under another (docs/architecture/import.md).
     *
     * @return array<int, int>
     */
    public function aliases(): array
    {
        if ($this->aliases !== null) {
            return $this->aliases;
        }

        $byFile = [];
        $byTerm = [];
        $bySlug = [];
        // The file's number of the term each map row holds, or null for one a live run brought.
        $holders = [];

        $rows = DB::table('import_map')
            ->leftJoin('terms', 'terms.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $this->sourceId)->where('import_map.object_type', 'term')
            ->get(['import_map.object_id', 'import_map.facts', 'terms.slug']);

        foreach ($rows as $row) {
            $facts = self::decoded($row->facts);
            // A live run keeps no slug among its facts: the term it made has it.
            $facts['slug'] ??= $row->slug;
            $taxonomy = (string) ($facts['taxonomy'] ?? '');
            $holders[(int) $row->object_id] = is_numeric($facts['file_term_id'] ?? null) ? (int) $facts['file_term_id'] : null;

            if (is_numeric($facts['file_term_id'] ?? null)) {
                $byFile[(int) $facts['file_term_id']] = (int) $row->object_id;
            }

            if ((int) ($facts['term_id'] ?? 0) > 0) {
                $byTerm[$taxonomy.'|'.(int) $facts['term_id']] ??= (int) $row->object_id;
            }

            if (is_string($facts['slug'] ?? null) && $facts['slug'] !== '') {
                $bySlug[$taxonomy.'|'.$facts['slug']] ??= (int) $row->object_id;
            }
        }

        $aliases = [];

        foreach (DB::table('import_file_rows')->where('file_id', $this->fileId)->where('list', ItemRows::TERMS)
            ->get(['object_id', DB::raw("payload->>'taxonomy' as taxonomy"), DB::raw("payload->>'slug' as slug")]) as $row) {
            $id = (int) $row->object_id;
            $taxonomy = (string) $row->taxonomy;
            $mapped = $byFile[$id] ?? ($id > 0 ? $byTerm[$taxonomy.'|'.$id] ?? null : null) ?? $bySlug[$taxonomy.'|'.$row->slug] ?? null;

            if ($mapped !== null && $mapped !== $id) {
                $aliases[$id] = $mapped;
            } elseif ($mapped === null && array_key_exists($id, $holders) && $holders[$id] !== $id) {
                // Its number is another term's in the map: it takes one of its own.
                $aliases[$id] = self::apart($id);
            }
        }

        return $this->aliases = $aliases;
    }

    /**
     * A page of posts and pages of the types and statuses asked for, and of
     * those the run's choices bring, each filed under its terms' map IDs.
     *
     * @param  array<string, scalar>  $query
     */
    private function content(array $query, int $afterId, int $limit): SourcePage
    {
        $types = self::words($query['types'] ?? '');
        $statuses = self::words($query['statuses'] ?? '');
        $chosen = is_array($this->choices['types'] ?? null) ? $this->choices['types'] : [];
        // A custom type chosen to stay behind isn't asked for.
        $types = array_values(array_filter($types, fn (string $type): bool => ! array_key_exists($type, $chosen) || ($chosen[$type] ?? null) !== null));

        if (($this->choices['drafts'] ?? true) === false) {
            $statuses = $statuses === [] ? self::PUBLISHED : array_values(array_intersect($statuses, self::PUBLISHED));
        }

        $items = DB::table('import_file_rows')
            ->where('file_id', $this->fileId)
            ->where('list', ItemRows::CONTENT)
            ->when($afterId !== 0, fn ($q) => $q->where('object_id', '>', $afterId))
            // A file read before they were left out may still hold WordPress's own records.
            ->whereNotIn(DB::raw("payload->>'type'"), WordPressRecords::TYPES)
            ->whereRaw("payload->>'status' IS DISTINCT FROM ?", [WordPressRecords::AUTO_DRAFT])
            ->when($types !== [], fn ($q) => $q->whereIn(DB::raw("payload->>'type'"), $types))
            ->when($statuses !== [], fn ($q) => $q->whereIn(DB::raw("payload->>'status'"), $statuses))
            ->orderBy('object_id')
            ->limit($limit)
            ->pluck('payload')
            ->map(fn (mixed $payload): array => $this->filed(self::decoded($payload)))
            ->all();

        return self::paged($items, $limit);
    }

    /**
     * An item with its terms under their map IDs.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array<string, mixed>
     */
    private function filed(array $item): array
    {
        $aliases = $this->aliases();

        if ($aliases === [] || ! is_array($item['terms'] ?? null)) {
            return $item;
        }

        foreach ($item['terms'] as $taxonomy => $ids) {
            $item['terms'][$taxonomy] = array_map(fn (mixed $id): mixed => is_numeric($id) ? ($aliases[(int) $id] ?? (int) $id) : $id, is_array($ids) ? $ids : []);
        }

        return $item;
    }

    /**
     * A page of one kind of the old site's addresses, written when the run started.
     */
    private function urls(string $kind, int $afterId, int $limit): SourcePage
    {
        if (! in_array($kind, FileAddresses::KINDS, true)) {
            return new SourcePage([], null);
        }

        return $this->rows(FileAddresses::listOf($kind), $afterId, $limit);
    }

    /**
     * What a file says of the old site's settings: its address structure, as
     * its posts' addresses show it, and the bases chosen for its categories
     * and tags. A file has no other setting.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $bases = $this->bases();

        return [
            'home' => $this->home(),
            'siteurl' => $this->home(),
            'locale' => '',
            'options' => [
                'permalink_structure' => FileAddresses::structure($this->fileId),
                'category_base' => $bases['category_base'] === FileAddresses::CATEGORY_BASE ? '' : $bases['category_base'],
                'tag_base' => $bases['tag_base'] === FileAddresses::TAG_BASE ? '' : $bases['tag_base'],
            ],
        ];
    }

    /**
     * The file's menus and navigation, in the companion's `menus` shape, each
     * menu at the place of its own the plan page chooses.
     *
     * @return array<string, mixed>
     */
    private function menus(): array
    {
        $rows = fn (string $list): array => DB::table('import_file_rows')->where('file_id', $this->fileId)->where('list', $list)->orderBy('object_id')->pluck('payload')
            ->map(fn (mixed $payload): array => self::decoded($payload))->all();
        $menus = $rows(ItemRows::MENUS);
        $places = [];

        foreach ($menus as $menu) {
            $places[self::menuPlace((int) ($menu['id'] ?? 0))] = (int) ($menu['id'] ?? 0);
        }

        return ['menus' => $menus, 'navigation' => $rows(ItemRows::NAVIGATION), 'assigned_locations' => $places];
    }

    /**
     * The bases chosen for category and tag addresses, or WordPress's own.
     *
     * @return array{category_base: string, tag_base: string}
     */
    private function bases(): array
    {
        return self::basesOf($this->choices);
    }

    /**
     * The bases a run's choices give category and tag addresses, empty for
     * no prefix, or WordPress's own when none was chosen.
     *
     * @param  array<string, mixed>  $choices
     *
     * @return array{category_base: string, tag_base: string}
     */
    public static function basesOf(array $choices): array
    {
        // An empty base chosen is no prefix; none chosen is WordPress's own.
        $base = fn (string $key, string $default): string => is_string($choices[$key] ?? null) ? trim($choices[$key], '/ ') : $default;

        return ['category_base' => $base('category_base', FileAddresses::CATEGORY_BASE), 'tag_base' => $base('tag_base', FileAddresses::TAG_BASE)];
    }

    /**
     * The old site's address, without a closing slash.
     */
    private function home(): string
    {
        return rtrim((string) ImportFile::query()->whereKey($this->fileId)->value('site_address'), '/');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function paged(array $items, int $limit): SourcePage
    {
        $last = $items === [] ? null : end($items);

        return new SourcePage($items, count($items) >= $limit && is_array($last) ? (int) ($last['id'] ?? 0) : null);
    }

    /**
     * @return list<string>
     */
    private static function words(mixed $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', is_scalar($value) ? (string) $value : '')), fn (string $word): bool => $word !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(mixed $payload): array
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($decoded) ? $decoded : [];
    }
}
