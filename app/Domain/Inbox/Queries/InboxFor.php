<?php

/**
 * A person's inbox (I-221): their background results, newest first, a keyset
 * page at a time, each with the address its link leads to for them now, and
 * how many they haven't read. It writes nothing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Inbox\Queries;

use App\Domain\Navigation\ToolsHub;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Queries\Cursor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use stdClass;

/**
 * @phpstan-type InboxRow array{id: string, title: string, text: string|null, href: string|null, read: bool, at: string, owner: string, plugin: string|null, kind: string, result: string|null, place: string|null}
 */
final readonly class InboxFor
{
    /** The most items a page holds. */
    public const int MOST = 200;

    /** Where the unread count stops, so it costs the same however many there are. */
    public const int UNREAD_CAP = 100;

    /** A moment as PostgreSQL writes one, which is what a cursor carries. */
    private const string MOMENT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2})?$/';

    /** An item's ID: 26 lower-case letters and digits (I-418). */
    private const string ID = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    /** The columns an item is drawn from. */
    private const array COLUMNS = ['id', 'kind', 'owner', 'body', 'read_at', 'created_at'];

    /**
     * Returns a page of this person's items, newest first, at most $perPage
     * (1 to 200); with the cursor that opens the newer page, or null at the
     * first, and the one that opens the older page, or null at the last.
     *
     * @return array{rows: list<InboxRow>, newer: string|null, older: string|null}
     */
    public function page(User $person, ?string $after, ?string $before, int $perPage): array
    {
        $limit = max(1, min(self::MOST, $perPage));
        $back = self::cursor($before);
        $backwards = $back !== null;
        $cursor = $back ?? self::cursor($after);

        $query = DB::table('inbox_items')->select(self::COLUMNS)->where('user_id', $person->id);

        if ($cursor !== null) {
            // One row comparison, so the person's index answers it.
            $query->whereRaw('(created_at, id) '.($backwards ? '>' : '<').' (?::timestamptz, ?)', $cursor);
        }

        $order = $backwards ? 'asc' : 'desc';
        $found = $query->orderBy('created_at', $order)->orderBy('id', $order)->limit($limit + 1)->get();
        $more = $found->count() > $limit;
        $found = $found->take($limit);

        if ($backwards) {
            $found = $found->reverse()->values();
        }

        $first = $found->first();
        $last = $found->last();

        return [
            'rows' => self::shown($person, $found),
            'newer' => $first !== null && ($backwards ? $more : $cursor !== null) ? self::cursorAt($first) : null,
            'older' => $last !== null && ($backwards || $more) ? self::cursorAt($last) : null,
        ];
    }

    /**
     * Returns how many of this person's items are unread, counted up to
     * UNREAD_CAP.
     */
    public function unread(User $person): int
    {
        return (int) DB::scalar(
            'SELECT count(*) FROM (SELECT 1 FROM inbox_items WHERE user_id = ? AND read_at IS NULL LIMIT '.self::UNREAD_CAP.') AS unread',
            [$person->id],
        );
    }

    /**
     * Returns one of this person's items as the inbox draws it, or null when
     * they have none by that ID.
     *
     * @return InboxRow|null
     */
    public function find(User $person, string $id): ?array
    {
        if (preg_match(self::ID, $id) !== 1) {
            return null;
        }

        return self::shown($person, DB::table('inbox_items')->select(self::COLUMNS)->where('user_id', $person->id)->where('id', $id)->get())[0] ?? null;
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
     * The rows as the inbox draws them.
     *
     * @param  Collection<int, stdClass>  $found
     *
     * @return list<InboxRow>
     */
    private static function shown(User $person, Collection $found): array
    {
        $rows = [];
        $plugins = self::pluginLabels($found);

        $places = app(ToolsHub::class);

        foreach ($found as $row) {
            $body = json_decode((string) $row->body, true);
            $body = is_array($body) ? $body : [];
            $kind = (string) $row->kind;
            $link = $body['link'] ?? null;

            $rows[] = [
                'id' => (string) $row->id,
                'title' => is_string($body['title'] ?? null) ? $body['title'] : '',
                'text' => is_string($body['text'] ?? null) ? $body['text'] : null,
                'href' => self::href($person, $link),
                'read' => $row->read_at !== null,
                'at' => CarbonImmutable::parse((string) $row->created_at)->utc()->toIso8601String(),
                'owner' => (string) $row->owner,
                'plugin' => $plugins[(string) $row->owner] ?? null,
                'kind' => $kind,
                'result' => self::result($kind),
                'place' => is_array($link) && is_string($link['route'] ?? null) ? $places->placeFor($link['route']) : null,
            ];
        }

        return $rows;
    }

    /**
     * How the inbox draws this kind: failed, finished, warning, or none.
     */
    private static function result(string $kind): ?string
    {
        if (str_ends_with($kind, '.failed') || $kind === 'failed') {
            return 'failed';
        }

        if (str_ends_with($kind, '.finished') || $kind === 'finished') {
            return 'finished';
        }

        if (str_ends_with($kind, '.warning') || $kind === 'warning') {
            return 'warning';
        }

        return null;
    }

    /**
     * The label of each plugin these items are from, by its code: what the
     * inbox names it by (docs/plans/plugin-sockets-3.md). Core's own items
     * ask nothing.
     *
     * @param  Collection<int, stdClass>  $found
     *
     * @return array<string, string>
     */
    private static function pluginLabels(Collection $found): array
    {
        $codes = $found->pluck('owner')->map(fn (mixed $owner): string => (string) $owner)->reject(fn (string $owner): bool => $owner === 'core')->unique()->values()->all();

        if ($codes === []) {
            return [];
        }

        $labels = [];

        foreach (PluginRecord::query()->whereIn('code', $codes)->get(['name', 'code']) as $record) {
            $labels[(string) $record->code] = app(PluginRegistry::class)->find((string) $record->name)?->manifest?->label() ?? (string) $record->name;
        }

        return $labels;
    }

    /**
     * The address an item's link leads to for this person now, relative to
     * the site (rule 9), or null when there's none they can follow: its route
     * is gone, its parameters no longer fit, or they lack its ability.
     */
    private static function href(User $person, mixed $link): ?string
    {
        if (! is_array($link) || ! is_string($link['route'] ?? null) || ! Route::has($link['route'])) {
            return null;
        }

        $ability = $link['ability'] ?? null;

        if (is_string($ability) && ! $person->can($ability)) {
            return null;
        }

        try {
            return route($link['route'], is_array($link['parameters'] ?? null) ? $link['parameters'] : [], false);
        } catch (UrlGenerationException) {
            return null;
        }
    }
}
