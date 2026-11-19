<?php

/**
 * A catch-up's list of what WordPress deleted since it was imported
 * (I-345): each person, category, tag, file, post, page, comment and menu
 * the import brought that WordPress no longer has but Teme still does,
 * found by comparing WordPress's lists with the map a page at a time.
 * Nothing is deleted or changed. At its end it says how many earlier
 * failures the catch-up didn't meet again. Fifty seconds a job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Finish;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Menus\MenusPhase;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

final readonly class DeletedStep implements StepPhase
{
    /** Seconds a job works before it hands on to the next. */
    public const int BUDGET = 50;

    /** Objects of one kind listed before the rest are only counted. */
    public const int MOST_SAID = 500;

    /** The kinds compared, in order. */
    public const array KINDS = ['user', 'term', 'attachment', 'post', 'comment', 'menu'];

    /** Each kind's list: the kind, the companion's route, its query, a page's size, and the kind its items must be when the route lists several. */
    private const array LISTS = [
        ['user', 'users', [], 500, null],
        ['term', 'terms', [], 1000, null],
        ['attachment', 'urls', ['kind' => 'attachment_page'], 2000, 'attachment_page'],
        ['post', 'urls', ['kind' => 'post'], 2000, 'post'],
        ['comment', 'comments', [], 1000, null],
    ];

    /** Each kind's words when more than MOST_SAID are gone: the kind, one, more. */
    private const array NAMES = [
        ['user', 'person', 'people'], ['term', 'category or tag', 'categories and tags'], ['attachment', 'media file', 'media files'],
        ['post', 'post or page', 'posts and pages'], ['comment', 'comment', 'comments'], ['menu', 'menu', 'menus'],
    ];

    /** The map's object type for a WordPress post or page, and for a menu. */
    private const string POSTS = 'post';

    private const string MENUS = 'menu';

    /** Every status the content phase reads a post or page in, published included: one may be published between two reads. */
    private const string STATUSES = 'publish,future,draft,pending,private';

    /** Items read at once to confirm posts: the companion's most without rendered pages. */
    private const int CONFIRM_PAGE = 200;

    /** A map row's object ID as a number, for WordPress's own IDs. */
    private const string NUMBER = "CASE WHEN object_id ~ '^[0-9]{1,18}$' THEN object_id::bigint END";

    public function __construct(private ContentTypeRegistry $types, private GoneObjects $gone, private ImportMap $map) {}

    public function key(): string
    {
        return 'deleted';
    }

    public function order(): int
    {
        return 1250;
    }

    public function label(): string
    {
        return 'Deleted on WordPress';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return $kind === ImportRunKind::CatchUp;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        if ($cursor === null) {
            app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'catch-up.', $context->actorId()));
        }

        [$kind, $after, $said] = self::position($cursor);
        $started = microtime(true);
        $reads = 0;
        $messages = [];

        while ($kind !== null) {
            // A read must end inside the job: the client waits 45 seconds for an answer, the job 75.
            if ($reads > 0 && ! self::inTime($started, $context)) {
                return StepResult::continueAt("{$kind}:{$after}:{$said}", $messages);
            }

            if ($reads > 0 && $context->pauseMs > 0) {
                usleep(min($context->pauseMs, 5000) * 1000);
            }

            $reads++;
            [$gone, $next] = $kind === self::MENUS ? [$this->menus($context, $reader), null] : $this->page($kind, $context, $reader, $after, $started);

            foreach ($gone as $object) {
                if ($said < self::MOST_SAID) {
                    $messages[] = new ImportMessage('catch-up.deleted', $object['words'], ['kind' => $kind, 'wordpress_id' => $object['wordpressId'], 'teme_type' => $object['temeType'], 'teme_id' => $object['temeId']]);
                }

                $said++;
            }

            if ($next === null) {
                if ($said > self::MOST_SAID) {
                    $messages[] = self::more($kind, $said - self::MOST_SAID);
                }

                [$kind, $after, $said] = [self::following($kind), 0, 0];
            } else {
                $after = $next;
            }
        }

        return StepResult::done([...$messages, ...self::notRetried($this->map->failedBefore($context->sourceId, $context->runId))]);
    }

    /**
     * One page of a kind's list: what of the map between $after and the
     * page's last ID the page doesn't hold, still here; and the ID to carry
     * on from, null at the list's end.
     *
     * @return array{0: list<array{wordpressId: string, temeType: string, temeId: string, words: string}>, 1: int|null}
     */
    private function page(string $kind, ImportContext $context, SourceReader $reader, int $after, float $started): array
    {
        [, $route, $query, $limit, $listed] = self::listOf($kind);
        $page = $reader->page($route, $query, $after, $limit);
        $ids = [];

        foreach ($page->items as $item) {
            // A route that lists several kinds: only this kind's items count.
            if (($listed === null || ($item['kind'] ?? $listed) === $listed)) {
                $ids[(int) ($item['id'] ?? 0)] = true;
            }
        }

        $upper = $page->nextAfterId;
        $missing = array_values(array_filter(self::rows($context->sourceId, $kind, $after, $upper), fn (object $row): bool => ! isset($ids[(int) $row->object_id])));

        if ($kind === self::POSTS && $missing !== []) {
            ['gone' => $missing, 'through' => $through] = $this->unlisted($reader, $missing, $context, $started, $after);

            // Confirmed only so far in this job's time: the next job reads the list again from there.
            if ($through !== null) {
                $upper = $through;
            }
        }

        return [$this->gone->stillHere($missing), $upper];
    }

    /**
     * The map's rows of a kind with a Teme object, whose WordPress ID is
     * above $after and at most $upper (no end when null).
     *
     * @return list<object>
     */
    private static function rows(string $sourceId, string $kind, int $after, ?int $upper): array
    {
        return DB::table('import_map')
            ->where('source_id', $sourceId)->where('object_type', $kind)->whereNotNull('teme_id')
            ->whereRaw(self::NUMBER.' > ?', [$after])
            ->when($upper !== null, fn ($rows) => $rows->whereRaw(self::NUMBER.' <= ?', [$upper]))
            ->get(['object_id', 'teme_type', 'teme_id', 'facts'])->all();
    }

    /**
     * Of posts missing from the published list, those the content list, in
     * every status the content phase reads, doesn't give either: read from
     * just below the first, a page at a time, each page settling every post
     * up to its last ID; and, when the job's time ran out first, the ID it
     * settled through (null when it settled them all).
     *
     * @param  list<object>  $rows
     *
     * @return array{gone: list<object>, through: int|null}
     */
    private function unlisted(SourceReader $reader, array $rows, ImportContext $context, float $started, int $after): array
    {
        $waiting = [];

        foreach ($rows as $row) {
            $waiting[(int) $row->object_id] = $row;
        }

        ksort($waiting);
        $query = ['types' => implode(',', $this->wordpressTypes()), 'statuses' => self::STATUSES];
        $gone = [];
        $through = $after;

        while ($waiting !== []) {
            // A read must end inside the job: the client waits 45 seconds for an answer, the job 75.
            if (! self::inTime($started, $context)) {
                return ['gone' => $gone, 'through' => $through];
            }

            if ($context->pauseMs > 0) {
                usleep(min($context->pauseMs, 5000) * 1000);
            }

            $page = $reader->page('content', $query, array_key_first($waiting) - 1, self::CONFIRM_PAGE);
            $seen = [];

            foreach ($page->items as $item) {
                if (in_array($item['status'] ?? '', explode(',', self::STATUSES), true)) {
                    $seen[(int) ($item['id'] ?? 0)] = true;
                }
            }

            $last = $page->nextAfterId ?? PHP_INT_MAX;

            foreach ($waiting as $id => $row) {
                if ($id > $last) {
                    break;
                }

                if (! isset($seen[$id])) {
                    $gone[] = $row;
                }

                unset($waiting[$id]);
            }

            $through = $last;
        }

        return ['gone' => $gone, 'through' => null];
    }

    /**
     * Menus gone from the menus answer, which holds classic menus by term ID
     * and navigation posts as navigation:{ID}, whole; the ones still here.
     *
     * @return list<array{wordpressId: string, temeType: string, temeId: string, words: string}>
     */
    private function menus(ImportContext $context, SourceReader $reader): array
    {
        $answer = $reader->whole('menus');
        $kept = [];

        foreach ((array) ($answer['menus'] ?? []) as $menu) {
            if (is_array($menu) && isset($menu['id'])) {
                $kept[(string) $menu['id']] = true;
            }
        }

        foreach ((array) ($answer['navigation'] ?? []) as $menu) {
            if (is_array($menu) && isset($menu['id'])) {
                $kept[MenusPhase::BLOCK_MENU.$menu['id']] = true;
            }
        }

        $missing = DB::table('import_map')
            ->where('source_id', $context->sourceId)->where('object_type', self::MENUS)->whereNotNull('teme_id')
            ->get(['object_id', 'teme_type', 'teme_id', 'facts'])
            ->filter(fn (object $row): bool => ! isset($kept[(string) $row->object_id]))
            ->values()->all();

        return $this->gone->stillHere($missing);
    }

    /**
     * The WordPress names of the listed types that have one, without the
     * reusable block.
     *
     * @return list<string>
     */
    private function wordpressTypes(): array
    {
        $types = [];

        foreach ($this->types->all() as $type) {
            if ($type->listed && $type->wordpress !== null) {
                $types[] = $type->wordpress;
            }
        }

        return $types;
    }

    /**
     * Whether a read started now, after its pause, ends inside the job:
     * elapsed and pause below BUDGET less the 25 seconds an answer may take
     * beyond it.
     */
    private static function inTime(float $started, ImportContext $context): bool
    {
        return microtime(true) - $started + min($context->pauseMs, 5000) / 1000 < self::BUDGET - 25;
    }

    /**
     * The entry of LISTS for a kind.
     *
     * @return array{0: string, 1: string, 2: array<string, string>, 3: int, 4: string|null}
     */
    private static function listOf(string $kind): array
    {
        foreach (self::LISTS as $list) {
            if ($list[0] === $kind) {
                return $list;
            }
        }

        return self::LISTS[0];
    }

    /**
     * The kind, the ID to read after and the number said so far, from a
     * cursor; the first kind from the start when it isn't one.
     *
     * @return array{0: string|null, 1: int, 2: int}
     */
    private static function position(?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^([a-z]+):(\d{1,18}):(\d{1,9})$/', $cursor, $found) === 1 && in_array($found[1], self::KINDS, true)) {
            return [$found[1], (int) $found[2], (int) $found[3]];
        }

        return [self::KINDS[0], 0, 0];
    }

    /**
     * The kind after this one, null after the last.
     */
    private static function following(string $kind): ?string
    {
        $at = array_search($kind, self::KINDS, true);

        return $at === false ? null : (self::KINDS[$at + 1] ?? null);
    }

    /**
     * The message that says how many more of a kind were deleted than were
     * listed.
     */
    private static function more(string $kind, int $count): ImportMessage
    {
        foreach (self::NAMES as [$name, $one, $many]) {
            if ($name === $kind) {
                $words = $count === 1
                    ? "1 more {$one} deleted on WordPress since it was imported is still here, not listed."
                    : number_format($count)." more {$many} deleted on WordPress since they were imported are still here, not listed.";

                return new ImportMessage('catch-up.more', $words, ['kind' => $kind, 'count' => $count]);
            }
        }

        return new ImportMessage('catch-up.more', number_format($count).' more deleted on WordPress since they were imported are still here, not listed.', ['kind' => $kind, 'count' => $count]);
    }

    /**
     * The line that counts the earlier runs' failures this catch-up never
     * met, since it reads only what changed on WordPress; none when there
     * are none. An error, as the failures it counts are.
     *
     * @return list<ImportMessage>
     */
    private static function notRetried(int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $words = $count === 1
            ? '1 item that failed before wasn’t tried again, since a catch-up only fetches what changed on WordPress. Run a full import to retry it.'
            : number_format($count).' items that failed before weren’t tried again, since a catch-up only fetches what changed on WordPress. Run a full import to retry them.';

        return [ImportMessage::error('catch-up.not-retried', $words, ['count' => $count])];
    }
}
