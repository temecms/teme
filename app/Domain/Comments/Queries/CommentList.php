<?php

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\CommentStatusRegistry;
use App\Domain\Comments\CommentText;
use App\Domain\Contents\Queries\ContentAbilities;
use App\Domain\Support\Queries\Cursor;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Comments screen's list (1.8c): one keyset page of a view, newest
 * first, for the items the person looks after (CommentScope), items in the
 * trash left out; each view's count (C10); the item a list is narrowed to;
 * the order deleting many runs in; and what an Empty press covers (1.8c′).
 */
final class CommentList
{
    /** All: every comment readers see or wait on, as WordPress's All (C10). */
    public const string ALL = 'all';

    /** Rows per page when the request asks for a size the lists don't offer. */
    private const int PAGE = 50;

    /** @var list<int> The page sizes a person may choose (lists.per_page). */
    private const array PAGE_SIZES = [25, 50, 100];

    /**
     * The words readers see in a comment (D7): the text of its text nodes,
     * in document order, joined with nothing between them, never a key or
     * an attribute. The last argument keeps a malformed document from
     * raising; strict mode reads each node once.
     */
    private const string WORDS = <<<'SQL'
        (SELECT string_agg(t.value #>> '{}', '' ORDER BY t.n)
         FROM jsonb_path_query(comments.body, 'strict $.**.text', '{}', true) WITH ORDINALITY AS t(value, n))
        SQL;

    /** @var array<int, User> The people read in this request, by ID. */
    private array $viewers = [];

    /** @var array<string, ContentAbilities> The viewer's abilities, by type. */
    private array $abilities = [];

    public function __construct(
        private readonly CommentStatusRegistry $statuses,
        private readonly ContentTypeRegistry $types,
    ) {}

    /**
     * The views, All first, then each status a moderator may set, in the
     * registry's order (D4).
     *
     * @return list<string>
     */
    public function views(): array
    {
        return [self::ALL, ...$this->statuses->settable()];
    }

    /**
     * One page of a view (D4, D6, D7): its rows newest first, the cursors
     * either side of it, and the view in force, Pending when the request
     * names none that exists.
     *
     * @return array{rows: list<array<string, mixed>>, newer: string|null, older: string|null, view: string}
     */
    public function page(CommentListRequest $request): array
    {
        $viewer = $this->viewer($request->viewerId);
        $scope = CommentScope::forViewer($viewer);
        $view = in_array($request->view, $this->views(), true) ? $request->view : CommentStatus::PENDING;
        $perPage = in_array($request->perPage, self::PAGE_SIZES, true) ? $request->perPage : self::PAGE;
        // A before that does not decode (a hand-edited link) is no before: page one.
        $before = Cursor::decode($request->before, 1);
        $backwards = $before !== null;
        $cursor = $before ?? Cursor::decode($request->after, 1);

        // A page and an Empty press read one rule (1.8c′'s D4).
        $query = $this->base($scope, $view, $request->itemId, $request->search)
            ->leftJoin('comments as parent', 'parent.id', '=', 'comments.parent_id')
            ->select([
                'comments.id', 'comments.kind', 'comments.status', 'comments.parent_id', 'comments.author_name',
                'comments.author_email', 'comments.author_url', 'comments.author_user_id', 'comments.body',
                'comments.created_at', 'comments.content_id', 'contents.title', 'contents.type', 'contents.author_id',
                'parent.author_name as parent_name', 'parent.status as parent_status',
            ])
            // An address leaves the database only for those who see it (I-270 B, D14).
            ->selectRaw($scope->everyItem ? 'host(comments.ip) AS ip' : 'NULL AS ip');

        if ($cursor !== null) {
            $query->where('comments.id', $backwards ? '>' : '<', (string) $cursor[0]);
        }

        $rows = $query->orderBy('comments.id', $backwards ? 'asc' : 'desc')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        if ($backwards) {
            $rows = $rows->reverse()->values();
        }

        // Every row's, in one statement, so every page costs the same.
        $replies = $this->repliesTo($rows->map(fn (object $row): string => (string) $row->id)->values()->all());
        $first = $rows->first();
        $last = $rows->last();

        return [
            'rows' => $rows->map(fn (object $row): array => $this->row($row, $viewer, $replies))->values()->all(),
            'newer' => $first !== null && ($backwards ? $hasMore : $cursor !== null) ? Cursor::encode([(string) $first->id]) : null,
            'older' => $last !== null && ($backwards || $hasMore) ? Cursor::encode([(string) $last->id]) : null,
            'view' => $view,
        ];
    }

    /**
     * Each view's label and count for the items the person looks after,
     * narrowed to one item when asked, never by a search (C10, D5): Pending
     * and Approved summed from comment_stats, every other status counted
     * from comments, All the first two together.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function counts(int $viewerId, ?string $itemId = null): array
    {
        $scope = CommentScope::forViewer($this->viewer($viewerId));

        $kept = DB::table('comment_stats')
            ->join('contents', 'contents.id', '=', 'comment_stats.content_id')
            ->whereNull('contents.deleted_at')
            ->selectRaw('COALESCE(SUM(comment_stats.pending_count), 0) AS pending, COALESCE(SUM(comment_stats.approved_count), 0) AS approved');

        if ($itemId !== null) {
            $kept->where('comment_stats.content_id', $itemId);
        }

        $scope->constrain($kept);
        $sums = $kept->first();

        $counted = array_values(array_diff($this->statuses->settable(), [CommentStatus::PENDING, CommentStatus::APPROVED]));
        $parked = [];

        if ($counted !== []) {
            $others = DB::table('comments')
                ->join('contents', 'contents.id', '=', 'comments.content_id')
                ->whereNull('contents.deleted_at')
                ->whereIn('comments.status', $counted)
                ->groupBy('comments.status')
                ->select('comments.status')
                ->selectRaw('count(*) AS total');

            if ($itemId !== null) {
                $others->where('comments.content_id', $itemId);
            }

            $scope->constrain($others);

            foreach ($others->get() as $row) {
                $parked[(string) $row->status] = (int) $row->total;
            }
        }

        $pending = (int) ($sums->pending ?? 0);
        $approved = (int) ($sums->approved ?? 0);

        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $key === self::ALL ? 'All' : $this->statuses->get($key)->label,
            'count' => match ($key) {
                self::ALL => $pending + $approved,
                CommentStatus::PENDING => $pending,
                CommentStatus::APPROVED => $approved,
                default => $parked[$key] ?? 0,
            },
        ], $this->views());
    }

    /**
     * The comments a view shows the person, narrowed by an item and a search:
     * page()'s conditions, in one place, so a page and an Empty press read one
     * rule (1.8c′'s D4).
     */
    private function base(CommentScope $scope, string $view, ?string $itemId, ?string $search): Builder
    {
        $query = DB::table('comments')
            ->join('contents', 'contents.id', '=', 'comments.content_id')
            ->whereNull('contents.deleted_at');

        $scope->constrain($query);

        if ($view === self::ALL) {
            $query->whereIn('comments.status', [CommentStatus::PENDING, CommentStatus::APPROVED]);
        } else {
            $query->where('comments.status', $view);
        }

        if ($itemId !== null) {
            $query->where('comments.content_id', $itemId);
        }

        $search = trim((string) $search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $where) use ($like, $scope): void {
                $where->where('comments.author_name', 'ilike', $like)
                    ->orWhere('comments.author_email', 'ilike', $like)
                    ->orWhere('comments.author_url', 'ilike', $like)
                    ->orWhereRaw(self::WORDS.' ILIKE ?', [$like]);

                if ($scope->everyItem) {
                    $where->orWhereRaw('host(comments.ip) ILIKE ?', [$like]);
                }
            });
        }

        return $query;
    }

    /**
     * What an Empty spam or Empty trash press would do (1.8c′'s D6), for its
     * confirmation: how many comments it deletes, how many of those keep a
     * place as tombstones, and how many replies those have. One keeps a place
     * when a comment below it stays: one not being emptied that isn't a
     * tombstone, since a tombstone goes once nothing is left beneath it.
     *
     * @return array{count: int, tombstones: int, replies: int}
     */
    public function emptying(EmptyingRequest $request): array
    {
        $emptied = $this->emptySet($request)->select('comments.id');

        $row = DB::selectOne(<<<SQL
            WITH RECURSIVE emptied AS MATERIALIZED ({$emptied->toSql()}),
            below (root, id, status) AS (
                SELECT emptied.id, reply.id, reply.status
                FROM emptied JOIN comments reply ON reply.parent_id = emptied.id
                UNION ALL
                SELECT below.root, reply.id, reply.status
                FROM below JOIN comments reply ON reply.parent_id = below.id
            ),
            kept AS (
                SELECT DISTINCT below.root FROM below
                WHERE below.status <> 'removed'
                  AND NOT EXISTS (SELECT 1 FROM emptied WHERE emptied.id = below.id)
            )
            SELECT (SELECT count(*) FROM emptied) AS total,
                   (SELECT count(*) FROM kept) AS tombstones,
                   (SELECT count(*) FROM comments reply JOIN kept ON reply.parent_id = kept.root) AS replies
            SQL, $emptied->getBindings());

        return [
            'count' => (int) ($row->total ?? 0),
            'tombstones' => (int) ($row->tombstones ?? 0),
            'replies' => (int) ($row->replies ?? 0),
        ];
    }

    /**
     * Up to $limit of the comments an Empty press deletes (1.8c′'s D4),
     * deepest first and then by ID, so a reply always goes before the
     * comment it answers; each after $after, the last one the caller read,
     * so one that couldn't be deleted is never read twice.
     *
     * @param  array{id: string, depth: int}|null  $after
     *
     * @return list<array{id: string, depth: int}>
     */
    public function emptyingBatch(EmptyingRequest $request, int $limit, ?array $after = null): array
    {
        $query = $this->emptySet($request)
            ->select(['comments.id', 'comments.depth'])
            ->orderByDesc('comments.depth')
            ->orderBy('comments.id')
            ->limit($limit);

        if ($after !== null) {
            $query->where(fn (Builder $next): Builder => $next
                ->where('comments.depth', '<', $after['depth'])
                ->orWhere(fn (Builder $same): Builder => $same->where('comments.depth', $after['depth'])->where('comments.id', '>', $after['id'])));
        }

        return $query->get()
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'depth' => (int) $row->depth])
            ->values()
            ->all();
    }

    /**
     * The comments an Empty press covers: the list's own rule for the
     * person, view, item and search, on every page, and only those whose
     * status changed no later than the list was opened (C4). Only a status
     * Delete permanently takes from, whatever the request names.
     */
    private function emptySet(EmptyingRequest $request): Builder
    {
        $query = $this->base(CommentScope::forViewer($this->viewer($request->viewerId)), $request->view, $request->itemId, $request->search)
            ->where('comments.status_changed_at', '<=', $request->cutoff->format('Y-m-d H:i:s.uP'));

        if (! in_array($request->view, $this->statuses->deletable(), true)) {
            $query->whereRaw('false');
        }

        return $query;
    }

    /**
     * The comments among these IDs, deepest first and then oldest first:
     * the order deleting many runs in (D12), so a reply always goes before
     * the comment it answers. Each comes with its writer's name, for the
     * toast. Unknown IDs are left out; whether each may be deleted is
     * DeleteCommentPermanently's to decide.
     *
     * @param  list<string>  $ids
     *
     * @return list<array{id: string, authorName: string|null}>
     */
    public function deletionOrder(array $ids): array
    {
        return DB::table('comments')
            ->whereIn('id', $ids)
            ->orderByDesc('depth')
            ->orderBy('id')
            ->get(['id', 'author_name'])
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'authorName' => $row->author_name === null ? null : (string) $row->author_name])
            ->values()
            ->all();
    }

    /**
     * The item a list is narrowed to (D13): its ID, its title, its type's
     * word, its owner's name, and whether the person looks after its
     * comments; null for one that doesn't exist or is in the trash.
     *
     * @return array{id: string, title: string, singular: string, owner: string|null, mine: bool}|null
     */
    public function item(int $viewerId, string $itemId): ?array
    {
        $row = DB::table('contents')
            ->leftJoin('users', 'users.id', '=', 'contents.author_id')
            ->where('contents.id', $itemId)
            ->whereNull('contents.deleted_at')
            ->first(['contents.id', 'contents.title', 'contents.type', 'users.name as owner']);

        if ($row === null) {
            return null;
        }

        // The list's own rule, so a narrowed list and its refusal agree.
        $looksAfter = DB::table('contents')->where('contents.id', $itemId);
        CommentScope::forViewer($this->viewer($viewerId))->constrain($looksAfter);

        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'singular' => $this->word((string) $row->type),
            'owner' => $row->owner === null ? null : (string) $row->owner,
            'mine' => $looksAfter->exists(),
        ];
    }

    /**
     * One row as the screen draws it, keys in CommentRow's order.
     *
     * @param  array<string, int>  $replies  each comment's reply count, by ID
     *
     * @return array<string, mixed>
     */
    private function row(object $row, User $viewer, array $replies): array
    {
        $body = json_decode((string) $row->body, true);
        $parentId = $row->parent_id === null ? null : (string) $row->parent_id;
        $removed = $row->parent_status === CommentStatus::REMOVED;

        return [
            'id' => (string) $row->id,
            'kind' => (string) $row->kind,
            'status' => (string) $row->status,
            'parentId' => $parentId,
            'authorName' => (string) $row->author_name,
            'authorEmail' => $row->author_email === null ? null : (string) $row->author_email,
            'authorUrl' => $row->author_url === null ? null : (string) $row->author_url,
            'signedIn' => $row->author_user_id !== null,
            'ip' => $row->ip === null ? null : (string) $row->ip,
            'text' => is_array($body) ? CommentText::plain($body) : '',
            'parent' => $parentId === null ? null : [
                'authorName' => $removed || $row->parent_name === null ? null : (string) $row->parent_name,
                'removed' => $removed,
            ],
            'item' => [
                'id' => (string) $row->content_id,
                'title' => (string) $row->title,
                'editable' => $this->editable($viewer, (string) $row->type, $row->author_id === null ? null : (int) $row->author_id),
            ],
            'createdAt' => CarbonImmutable::parse((string) $row->created_at)->utc()->toIso8601String(),
            'replies' => $replies[(string) $row->id] ?? 0,
            'plain' => is_array($body) && CommentText::isPlain($body),
        ];
    }

    /**
     * How many replies each of these comments has, every status counted
     * (I-577), in one statement; none for an empty page.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, int>
     */
    private function repliesTo(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = [];

        foreach (DB::table('comments')->whereIn('parent_id', $ids)->groupBy('parent_id')->select('parent_id')->selectRaw('count(*) AS total')->get() as $row) {
            $counts[(string) $row->parent_id] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * ContentPolicy::update's rule for an item already read: the type's
     * edit.any, or its edit.own on the viewer's own item. The abilities are
     * read once a person and a type.
     */
    private function editable(User $viewer, string $type, ?int $authorId): bool
    {
        $abilities = $this->abilities["{$viewer->id}:{$type}"] ??= ContentAbilities::forUser($viewer, $type);

        return $abilities->editAny || ($abilities->editOwn && $authorId !== null && $authorId === (int) $viewer->id);
    }

    /**
     * The type's word for an item, lower-cased; "item" for a type no longer
     * registered.
     */
    private function word(string $type): string
    {
        return $this->types->has($type) ? mb_strtolower($this->types->get($type)->singular) : 'item';
    }

    /**
     * The person with this ID, read once a request.
     */
    private function viewer(int $id): User
    {
        return $this->viewers[$id] ??= User::query()->findOrFail($id);
    }
}
