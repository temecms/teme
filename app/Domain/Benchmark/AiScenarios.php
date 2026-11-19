<?php

/**
 * Tools › AI activity at the size a busy site's year leaves (I-318): the
 * record's first page and one halfway down, a month's first page and one
 * halfway into it, a person's month, a task's month, and the spend and
 * filters the screen shows beside them. Each calls the screen's own queries
 * and changes nothing, so there is nothing to put back.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark;

use App\Domain\Ai\Queries\AiActivityFilters;
use App\Domain\Ai\Queries\AiActivityList;
use App\Domain\Ai\Queries\AiActivityRequest;
use App\Domain\Ai\Queries\AiMonthSummary;
use App\Domain\Support\Queries\Cursor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type Scenario array{name: string, group: string, indexOrder: bool, run: Closure(int): mixed, queriesLike?: string, reads?: list<string>}
 */
final class AiScenarios
{
    /** Rows a page of the record shows: the screen's own page size. */
    private const int PAGE = 50;

    /** How many months back the filtered month is: well inside the twelve the record keeps. */
    private const int MONTHS_BACK = 6;

    /** The task the task filter asks for: the rarest, so its page walks furthest. */
    private const string RAREST_TASK = 'alt-text';

    /** The index the record's pages and a month's must read. */
    private const string CREATED_INDEX = 'ai_activity_created_index';

    /** @var list<string> What the last all() noted beside the table. */
    private array $notes = [];

    public function __construct(
        private readonly AiActivityList $list,
        private readonly AiMonthSummary $summary,
        private readonly AiActivityFilters $filters,
    ) {}

    /**
     * Returns the record's scenarios, or none, with a note, when the data
     * set holds no AI requests.
     *
     * @return list<Scenario>
     */
    public function all(): array
    {
        $this->notes = [];
        $rows = DB::table('ai_activity')->count();

        if ($rows === 0) {
            $this->notes[] = 'AI activity: not measured; the data set holds no AI requests (teme:bench:seed --ai-activity).';

            return [];
        }

        $month = CarbonImmutable::now('UTC')->subMonthsNoOverflow(self::MONTHS_BACK)->format('Y-m');
        [$from, $to] = self::bounds($month);
        $inMonth = DB::table('ai_activity')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        $found = DB::table('users')->where('email', 'author100@bench.test')->value('id');
        $person = is_numeric($found) ? (int) $found : null;
        $deep = intdiv($rows, 2);

        $this->notes[] = sprintf(
            'AI activity: %s requests; the month filtered is %s, with %s of them; the deep page starts %s rows down.',
            number_format($rows),
            $month,
            number_format($inMonth),
            number_format($deep),
        );

        $page = fn (AiActivityRequest $request): Closure => fn (int $i): array => $this->list->page($request);
        $reads = [self::CREATED_INDEX];

        return [
            ['name' => 'ai activity: page one', 'group' => 'list', 'indexOrder' => true, 'reads' => $reads, 'run' => $page(new AiActivityRequest(perPage: self::PAGE))],
            ['name' => 'ai activity: halfway down', 'group' => 'list', 'indexOrder' => true, 'queriesLike' => 'ai activity: page one', 'reads' => $reads, 'run' => $page(new AiActivityRequest(after: $this->cursorAt($deep), perPage: self::PAGE))],
            ['name' => 'ai activity: one month, page one', 'group' => 'filtered', 'indexOrder' => true, 'reads' => $reads, 'run' => $page(new AiActivityRequest(month: $month, perPage: self::PAGE))],
            ['name' => 'ai activity: one month, halfway in', 'group' => 'filtered', 'indexOrder' => true, 'queriesLike' => 'ai activity: one month, page one', 'reads' => $reads, 'run' => $page(new AiActivityRequest(month: $month, after: $this->cursorAt(intdiv($inMonth, 2), $month), perPage: self::PAGE))],
            ['name' => 'ai activity: one person, one month', 'group' => 'filtered', 'indexOrder' => false, 'run' => $page(new AiActivityRequest(person: $person, month: $month, perPage: self::PAGE))],
            ['name' => 'ai activity: one task, one month', 'group' => 'filtered', 'indexOrder' => false, 'run' => $page(new AiActivityRequest(task: self::RAREST_TASK, month: $month, perPage: self::PAGE))],
            ['name' => 'ai activity: the month\'s spend and the filters', 'group' => 'list', 'indexOrder' => false, 'run' => fn (int $i): array => [
                $this->summary->of($month),
                $this->filters->people(null),
                $this->filters->tasks(null),
                $this->filters->months($month),
            ]],
        ];
    }

    /**
     * Returns what the last all() noted beside the table.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Returns the cursor that opens the page after the row $offset rows down
     * the record, newest first, inside $month when one is given; or null when
     * the record is shorter.
     */
    private function cursorAt(int $offset, ?string $month = null): ?string
    {
        $query = DB::table('ai_activity')->select(['created_at', 'id'])->orderByDesc('created_at')->orderByDesc('id')->offset($offset)->limit(1);

        if ($month !== null) {
            [$from, $to] = self::bounds($month);
            $query->where('created_at', '>=', $from)->where('created_at', '<', $to);
        }

        $row = $query->first();

        return $row === null ? null : Cursor::encode([(string) $row->created_at, (string) $row->id]);
    }

    /**
     * The first instant of a UTC month and of the next, as the list's month
     * filter reads them.
     *
     * @return array{0: string, 1: string}
     */
    private static function bounds(string $month): array
    {
        $from = CarbonImmutable::parse($month, 'UTC')->startOfMonth();

        return [$from->format('Y-m-d H:i:sP'), $from->addMonth()->format('Y-m-d H:i:sP')];
    }
}
