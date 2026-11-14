<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Contents\Queries\ContentAbilities;
use App\Domain\Dashboard\MainType;
use App\Domain\Settings\Queries\FindSettings;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The command palette's search (I-103): articles by their title, and media,
 * terms, users and settings screens. Each group is offered only to someone
 * who may open its screen, and matches by that screen's own rule, so the
 * palette shows what the screen would. One query per group, however many
 * rows it finds. The published text is searched in the list, which the
 * palette links to (B-199). It reads only.
 */
final readonly class AdminSearch
{
    public function __construct(
        private ContentTypeRegistry $types,
        private TaxonomyRegistry $taxonomies,
        private FindSettings $findSettings,
    ) {}

    /**
     * Up to `$limit` matches in each group, for a query already trimmed and
     * not empty. A group the person may not open is empty, and costs no query.
     *
     * @return array{
     *     content: list<array{id: string, title: string, subtitle: string, url: string, kind: string, canEdit: bool}>,
     *     media: list<array{id: string, title: string, subtitle: string, url: string, kind: string}>,
     *     terms: list<array{id: string, title: string, subtitle: string, url: string, kind: string}>,
     *     users: list<array{id: string, title: string, subtitle: string, url: string, kind: string}>,
     *     settings: list<array{id: string, title: string, subtitle: string, url: string, kind: string}>,
     *     textSearch: array{url: string, plural: string}|null
     * }
     */
    public function run(string $query, User $actor, int $limit = 20): array
    {
        return [
            'content' => $actor->can('content.create') ? $this->content($query, $actor, $limit) : [],
            'media' => $actor->can('media.upload') ? $this->media($query, $limit) : [],
            'terms' => $this->terms($query, $actor, $limit),
            'users' => $actor->can('users.manage') ? $this->users($query, $limit) : [],
            'settings' => $this->settings($query, $actor, $limit),
            'textSearch' => $this->textSearch($query, $actor),
        ];
    }

    /**
     * Where the published text is searched: the main type's list with its
     * In text search, for someone who may open that list. No query can find
     * text matches in bounded time at a million rows, since a word no
     * article holds walks the table (B-199); the list pages its matches.
     *
     * @return array{url: string, plural: string}|null
     */
    private function textSearch(string $term, User $actor): ?array
    {
        $type = MainType::get();

        if (! $actor->can($type->ability('create'))) {
            return null;
        }

        return [
            'url' => route('content.index', ['type' => $type->segment, 'search' => $term, 'in' => 'body'], false),
            'plural' => $type->plural,
        ];
    }

    /**
     * Articles whose title holds the query, by the list's own title rule,
     * closest first by word similarity: the title holding the whole word
     * ranks above a short title holding part of it. The trigram GiST index
     * gives both the filter and the order, so the first page costs one index
     * scan however many titles match (B-199). Whether the actor may edit
     * each is ContentPolicy::update's rule as SQL, one CASE over the type,
     * from each type's abilities read once (I-409).
     *
     * @return list<array{id: string, title: string, subtitle: string, url: string, kind: string, canEdit: bool}>
     */
    private function content(string $term, User $actor, int $limit): array
    {
        $listed = array_values(array_filter($this->types->all(), fn (ContentType $type): bool => $type->listed));
        $like = '%'.addcslashes($term, '%_\\').'%';
        $actorId = (int) $actor->getAuthIdentifier();

        $case = 'CASE contents.type';
        $bindings = [];

        foreach ($listed as $type) {
            [$sql, $values] = ContentAbilities::forUser($actor, $type->key)->canEditSql('contents.author_id', $actorId);
            $case .= " WHEN ? THEN ({$sql})";
            $bindings = [...$bindings, $type->key, ...$values];
        }

        $case .= ' ELSE false END';

        $rows = DB::table('contents')
            ->whereIn('contents.type', array_map(fn (ContentType $type): string => $type->key, $listed))
            ->whereNull('contents.deleted_at')
            ->where('contents.title', 'ilike', $like)
            ->select(['contents.id', 'contents.title', 'contents.type', 'contents.status'])
            ->selectRaw("({$case}) AS can_edit", $bindings)
            // The distance alone, with no tie-break, so the index gives the
            // order and nothing is sorted.
            ->orderByRaw('? <<-> contents.title', [$term])
            ->limit($limit)
            ->get();

        $byKey = [];

        foreach ($listed as $type) {
            $byKey[$type->key] = $type;
        }

        return $rows->map(function (object $row) use ($byKey, $term): array {
            $type = $byKey[(string) $row->type];
            $canEdit = (bool) $row->can_edit;

            return [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'subtitle' => $type->singular.' · '.ucfirst((string) $row->status),
                // Someone who may not edit it sees it where the list shows it.
                'url' => $canEdit
                    ? route('content.edit', ['content' => $row->id], false)
                    : route('content.index', ['type' => $type->segment, 'search' => $term], false),
                'kind' => 'content',
                'canEdit' => $canEdit,
            ];
        })->values()->all();
    }

    /**
     * Media, by the library's own rule (media_search_text), live items only.
     *
     * @return list<array{id: string, title: string, subtitle: string, url: string, kind: string}>
     */
    private function media(string $term, int $limit): array
    {
        return DB::table('media')
            ->whereNull('deleted_at')
            ->whereRaw('media_search_text(media.original_filename, media.title, media.alt, media.caption) LIKE ?', ['%'.addcslashes(mb_strtolower($term), '%_\\').'%'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'original_filename'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'subtitle' => (string) $row->original_filename,
                'url' => route('media.index', ['search' => $term], false),
                'kind' => 'media',
            ])->values()->all();
    }

    /**
     * Terms, by the terms screen's own rule (a prefix of the name), in the
     * taxonomies the actor may manage.
     *
     * @return list<array{id: string, title: string, subtitle: string, url: string, kind: string}>
     */
    private function terms(string $term, User $actor, int $limit): array
    {
        $managed = [];

        foreach ($this->taxonomies->all() as $taxonomy) {
            if ($actor->can($taxonomy->ability)) {
                $managed[$taxonomy->key] = $taxonomy;
            }
        }

        if ($managed === []) {
            return [];
        }

        return DB::table('terms')
            ->whereIn('taxonomy', array_keys($managed))
            ->whereRaw('lower(name) LIKE ?', [addcslashes(mb_strtolower($term), '%_\\').'%'])
            ->orderBy('name')->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'taxonomy'])
            ->map(function (object $row) use ($managed): array {
                /** @var Taxonomy $taxonomy */
                $taxonomy = $managed[(string) $row->taxonomy];

                return [
                    'id' => (string) $row->id,
                    'title' => (string) $row->name,
                    'subtitle' => $taxonomy->singular,
                    'url' => route('terms.index', ['taxonomy' => $taxonomy->segment, 'search' => (string) $row->name], false),
                    'kind' => 'term',
                ];
            })->values()->all();
    }

    /**
     * People, by name or email. The Users screen has no search of its own to
     * copy.
     *
     * @return list<array{id: string, title: string, subtitle: string, url: string, kind: string}>
     */
    private function users(string $term, int $limit): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return DB::table('users')
            ->where(fn (Builder $query) => $query->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like))
            ->orderBy('name')->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'email'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->name,
                'subtitle' => (string) $row->email,
                'url' => route('users.index', [], false),
                'kind' => 'user',
            ])->values()->all();
    }

    /**
     * The settings the actor may change whose label or description holds
     * every word, each opening at its field. The Settings pages themselves
     * are the palette's Go to, from the menu.
     *
     * @return list<array{id: string, title: string, subtitle: string, url: string, kind: string}>
     */
    private function settings(string $term, User $actor, int $limit): array
    {
        return array_map(fn (array $setting): array => [
            'id' => $setting['key'],
            'title' => $setting['label'],
            'subtitle' => 'Settings › '.$setting['screen'],
            'url' => $setting['href'],
            'kind' => 'setting',
        ], $this->findSettings->run($term, $actor, $limit)['settings']);
    }
}
