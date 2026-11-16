<?php

declare(strict_types=1);

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Support\Queries\Cursor;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tools › AI activity's list (I-1307): a page of AI requests at a time, newest
 * first, by keyset, narrowed by person, task, month and plugin, each as the
 * screen draws it. It reads the record and, only to name what a row is about,
 * the people, items and files it points at; it writes nothing.
 *
 * @phpstan-type ActivityRow array{id: string, at: string, person: array{id: int|null, name: string|null}, task: array{key: string, label: string}, variant: array{key: string, label: string}|null, plugin: array{code: string, label: string}|null, subject: array{kind: string, id: string, title: string|null, kindLabel: string, state: string}, outcome: array{key: string, label: string}, why: string|null, provider: string|null, model: string|null, prompt: array{key: string, version: int}, tokens: array{input: int, output: int, cacheRead: int, cacheWrite: int}, costMicrounits: int|null, latencyMs: int}
 */
final readonly class AiActivityList
{
    /** The most rows a page holds. */
    public const int MOST = 200;

    /** A moment as PostgreSQL writes one, which is what a cursor carries. */
    private const string MOMENT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2})?$/';

    /** A row's ID: 26 lower-case letters and digits (I-418). */
    private const string ID = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    /** The columns a row is drawn from. */
    private const array COLUMNS = [
        'a.id', 'a.user_id', 'a.task', 'a.variant', 'a.provider', 'a.model', 'a.prompt_key', 'a.prompt_version',
        'a.subject_type', 'a.subject_id', 'a.outcome', 'a.error_type', 'a.input_tokens', 'a.output_tokens',
        'a.cache_read_tokens', 'a.cache_write_tokens', 'a.cost_microunits', 'a.latency_ms', 'a.created_at', 'a.plugin',
    ];

    /** The plugin filter's value for core's own requests. */
    public const string TEME = 'teme';

    public function __construct(private AiTasks $tasks, private AiProviders $providers, private ContentTypeRegistry $types, private PluginAiTasks $pluginTasks) {}

    /**
     * Returns a page of the record, newest first, at most the request's page
     * size (1 to 200), narrowed by its person, task, month and plugin; with the cursor
     * that opens the page of newer rows, or null at the first page, and the
     * one that opens the page of older rows, or null at the last.
     *
     * @return array{rows: list<ActivityRow>, newer: string|null, older: string|null}
     */
    public function page(AiActivityRequest $request): array
    {
        $limit = max(1, min(self::MOST, $request->perPage));
        $before = self::cursor($request->before);
        $backwards = $before !== null;
        $cursor = $before ?? self::cursor($request->after);

        $query = DB::table('ai_activity as a')
            ->select(self::COLUMNS)
            ->when($request->person !== null, fn (Builder $found): Builder => $found->where('a.user_id', $request->person))
            ->when($request->task !== null, fn (Builder $found): Builder => $found->where('a.task', $request->task))
            ->when($request->month !== null, fn (Builder $found): Builder => self::within($found, (string) $request->month))
            ->when($request->plugin === self::TEME, fn (Builder $found): Builder => $found->whereNull('a.plugin'))
            ->when($request->plugin !== null && $request->plugin !== self::TEME, fn (Builder $found): Builder => $found->where('a.plugin', $request->plugin));

        if ($cursor !== null) {
            // One row comparison, so the index on the moment and the ID answers it.
            $query->whereRaw('(a.created_at, a.id) '.($backwards ? '>' : '<').' (?::timestamptz, ?)', $cursor);
        }

        $order = $backwards ? 'asc' : 'desc';
        $found = $query->orderBy('a.created_at', $order)->orderBy('a.id', $order)->limit($limit + 1)->get();
        $more = $found->count() > $limit;
        $found = $found->take($limit);

        if ($backwards) {
            $found = $found->reverse()->values();
        }

        $first = $found->first();
        $last = $found->last();

        return [
            'rows' => $this->shown($found),
            'newer' => $first !== null && ($backwards ? $more : $cursor !== null) ? self::cursorAt($first) : null,
            'older' => $last !== null && ($backwards || $more) ? self::cursorAt($last) : null,
        ];
    }

    /**
     * Returns one request as the list shows it, or null when the record has
     * none by that ID, for the drawer an address opens.
     *
     * @return ActivityRow|null
     */
    public function find(string $id): ?array
    {
        return $this->shown(DB::table('ai_activity as a')->select(self::COLUMNS)->where('a.id', $id)->get())[0] ?? null;
    }

    /**
     * Returns an option's or a task's key in words: its hyphens as spaces and
     * a capital, so "fix-spelling-and-grammar" reads "Fix spelling and
     * grammar".
     */
    public static function words(string $key): string
    {
        return ucfirst(str_replace('-', ' ', $key));
    }

    /**
     * The moment and the ID a cursor carries, or null when it carries
     * anything else: a cursor someone edited never reaches PostgreSQL.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function cursor(?string $value): ?array
    {
        $at = Cursor::decode($value, 2);

        if ($at === null || ! is_string($at[0]) || ! is_string($at[1]) || preg_match(self::MOMENT, $at[0]) !== 1 || preg_match(self::ID, $at[1]) !== 1) {
            return null;
        }

        return [$at[0], $at[1]];
    }

    /**
     * The cursor that opens the page beyond this row.
     */
    private static function cursorAt(stdClass $row): string
    {
        return Cursor::encode([(string) $row->created_at, (string) $row->id]);
    }

    /**
     * The rows made in a UTC month, the month the monthly total counts: from
     * its first instant to the next month's.
     */
    private static function within(Builder $query, string $month): Builder
    {
        $from = CarbonImmutable::parse($month, 'UTC')->startOfMonth();

        return $query
            ->where('a.created_at', '>=', $from->format('Y-m-d H:i:sP'))
            ->where('a.created_at', '<', $from->addMonth()->format('Y-m-d H:i:sP'));
    }

    /**
     * The rows as the screen draws them, with the people, items and files they
     * point at read in one statement each.
     *
     * @param  Collection<int, stdClass>  $found
     *
     * @return list<ActivityRow>
     */
    private function shown(Collection $found): array
    {
        if ($found->isEmpty()) {
            return [];
        }

        $names = $this->peopleNamed($found);
        $items = $this->itemsNamed($found->where('subject_type', AiSubjectType::Content->value));
        $images = $this->mediaNamed($found->where('subject_type', AiSubjectType::Media->value));
        $labels = [];

        foreach ($this->tasks->all() as $task) {
            $labels[$task->key()] = $task->label();
        }

        $plugins = $found->pluck('plugin')->filter()->isEmpty() ? [] : $this->pluginTasks->labels();

        $providers = [];

        foreach ($found->pluck('provider')->filter()->unique() as $key) {
            $providers[(string) $key] = $this->providers->find((string) $key)?->label() ?? (string) $key;
        }

        $rows = [];

        foreach ($found as $row) {
            $task = (string) $row->task;
            $outcome = AiOutcome::from((string) $row->outcome);
            $failure = $row->error_type === null ? null : AiFailureType::tryFrom((string) $row->error_type);
            $media = $row->subject_type === AiSubjectType::Media->value;
            $none = $row->subject_type === AiSubjectType::None->value;
            $known = $media ? ($images[(string) $row->subject_id] ?? null) : ($items[(string) $row->subject_id] ?? null);
            $plugin = $row->plugin === null ? null : (string) $row->plugin;

            $rows[] = [
                'id' => (string) $row->id,
                'at' => CarbonImmutable::parse((string) $row->created_at)->utc()->toIso8601String(),
                'person' => $row->user_id === null
                    ? ['id' => null, 'name' => null]
                    : ['id' => (int) $row->user_id, 'name' => $names[(int) $row->user_id] ?? null],
                'task' => ['key' => $task, 'label' => $plugin === null ? ($labels[$task] ?? self::words($task)) : ($plugins[$plugin]['tasks'][$task] ?? self::words($task))],
                'variant' => $row->variant === null ? null : ['key' => (string) $row->variant, 'label' => self::words((string) $row->variant)],
                'plugin' => $plugin === null ? null : ['code' => $plugin, 'label' => $plugins[$plugin]['label'] ?? $plugin],
                'subject' => [
                    'kind' => (string) $row->subject_type,
                    'id' => (string) $row->subject_id,
                    'title' => $known['title'] ?? null,
                    'kindLabel' => $none ? __('No item') : ($known['kindLabel'] ?? ($media ? 'Image' : 'Item')),
                    'state' => $none ? 'none' : ($known['state'] ?? 'gone'),
                ],
                'outcome' => ['key' => $outcome->value, 'label' => $outcome->label()],
                'why' => $failure?->sentence(),
                'provider' => $row->provider === null ? null : $providers[(string) $row->provider],
                'model' => $row->model === null ? null : (string) $row->model,
                'prompt' => ['key' => (string) $row->prompt_key, 'version' => (int) $row->prompt_version],
                'tokens' => [
                    'input' => (int) $row->input_tokens,
                    'output' => (int) $row->output_tokens,
                    'cacheRead' => (int) $row->cache_read_tokens,
                    'cacheWrite' => (int) $row->cache_write_tokens,
                ],
                'costMicrounits' => $row->cost_microunits === null ? null : (int) $row->cost_microunits,
                'latencyMs' => (int) $row->latency_ms,
            ];
        }

        return $rows;
    }

    /**
     * Each asker's name, by their number; none for a person who was removed.
     *
     * @param  Collection<int, stdClass>  $found
     *
     * @return array<int, string>
     */
    private function peopleNamed(Collection $found): array
    {
        $ids = $found->pluck('user_id')->filter(fn (mixed $id): bool => $id !== null)->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $names = [];

        if ($ids === []) {
            return $names;
        }

        foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'name']) as $user) {
            $names[(int) $user->id] = (string) $user->name;
        }

        return $names;
    }

    /**
     * Each post's or page's title now, its type's singular name, and whether
     * it is live or in the trash; none for one that was deleted for good.
     *
     * @param  Collection<int, stdClass>  $rows
     *
     * @return array<string, array{title: string, kindLabel: string, state: string}>
     */
    private function itemsNamed(Collection $rows): array
    {
        $ids = $rows->pluck('subject_id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $items = [];

        if ($ids === []) {
            return $items;
        }

        foreach (DB::table('contents')->whereIn('id', $ids)->get(['id', 'type', 'title', 'deleted_at']) as $item) {
            $type = (string) $item->type;

            $items[(string) $item->id] = [
                'title' => (string) $item->title,
                'kindLabel' => $this->types->has($type) ? $this->types->get($type)->singular : 'Item',
                'state' => $item->deleted_at === null ? 'present' : 'trashed',
            ];
        }

        return $items;
    }

    /**
     * Each image's name now, and whether it is live or in the trash; none for
     * one that was deleted for good. Not files(): the architecture tests read
     * that name as listing a folder.
     *
     * @param  Collection<int, stdClass>  $rows
     *
     * @return array<string, array{title: string, kindLabel: string, state: string}>
     */
    private function mediaNamed(Collection $rows): array
    {
        $ids = $rows->pluck('subject_id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $images = [];

        if ($ids === []) {
            return $images;
        }

        foreach (DB::table('media')->whereIn('id', $ids)->get(['id', 'name', 'deleted_at']) as $image) {
            $images[(string) $image->id] = [
                'title' => (string) $image->name,
                'kindLabel' => 'Image',
                'state' => $image->deleted_at === null ? 'present' : 'trashed',
            ];
        }

        return $images;
    }
}
