<?php

declare(strict_types=1);

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Queries\AiActivityList;
use App\Domain\Ai\Queries\AiActivityRequest;
use App\Domain\Ai\Queries\AiSpend;
use App\Domain\Support\Queries\Cursor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    aiFake();
    aiTask('tidy');
    aiTask('outline');
    $this->ada = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->eze = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->item = lifecycleDraft($this->eze, 'Harbour news');

    // A page of the list as the screen asks for it; $request names AiActivityRequest's parameters.
    $this->list = fn (array $request = []): array => app(AiActivityList::class)->page(new AiActivityRequest(...$request));
    $this->ids = fn (array $page): array => array_column($page['rows'], 'id');

    // The plan PostgreSQL makes for a page's rows, with the rival plans ruled out, so a few hundred rows show whether an index can answer it. Skip the count that names the range.
    $this->plan = function (array $request): string {
        $statement = collect(planStatements(fn () => ($this->list)($request)))
            ->first(fn (array $entry): bool => str_contains($entry['query'], 'from "ai_activity" as "a"') && str_contains(strtolower($entry['query']), 'order by'));

        expect($statement)->not->toBeNull();

        return DB::transaction(function () use ($statement): string {
            DB::statement('SET LOCAL enable_seqscan = off');
            DB::statement('SET LOCAL enable_bitmapscan = off');
            DB::statement('SET LOCAL enable_sort = off');

            return collect(DB::select('EXPLAIN '.$statement['query'], $statement['bindings']))->pluck('QUERY PLAN')->implode("\n");
        });
    };

    // Ada's rows sit between Eze's, and a second task's among the first's.
    $this->fill = function (): void {
        actvFiller($this->eze, 150);
        actvFiller($this->ada, 5, 'outline');
        actvFiller($this->eze, 150);
    };
});

it('shows a request as one row: who, which task and option, which item, how it ended, which model, the tokens and the cost (I-1307)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 14:03:22', 'UTC'));
    $id = actvRow($this->eze, ['subjectId' => $this->item->id, 'model' => 'fake-text-2026-11', 'usage' => new AiUsage(input: 1_200, output: 80, cacheRead: 300, cacheWrite: 20)]);

    $page = ($this->list)();

    expect($page['rows'])->toHaveCount(1)
        ->and($page['from'])->toBe(1)
        ->and($page['to'])->toBe(1)
        ->and($page['total'])->toBeNull()
        ->and($page['rows'][0])->toBe([
            'id' => $id,
            'at' => '2026-11-12T14:03:22+00:00',
            'person' => ['id' => $this->eze->id, 'name' => 'Eze Editor'],
            'task' => ['key' => 'tidy', 'label' => 'Tidy'],
            'variant' => ['key' => 'shorter', 'label' => 'Shorter'],
            'plugin' => null,
            'subject' => ['kind' => 'content', 'id' => $this->item->id, 'title' => 'Harbour news', 'kindLabel' => 'Post', 'state' => 'present'],
            'outcome' => ['key' => 'suggested', 'label' => 'Suggested'],
            'why' => null,
            'provider' => aiFake()->label(),
            'model' => 'fake-text-2026-11',
            'prompt' => ['key' => 'tidy', 'version' => 1],
            'tokens' => ['input' => 1_200, 'output' => 80, 'cacheRead' => 300, 'cacheWrite' => 20],
            'costMicrounits' => 1_400,
            'latencyMs' => 2_300,
        ])
        ->and($page['newer'])->toBeNull()
        ->and($page['older'])->toBeNull();
});

it('lists the newest first, and a tie by ID', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    $tied = [actvRow($this->eze), actvRow($this->eze)];
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:01', 'UTC'));
    $newest = actvRow($this->eze);

    rsort($tied);

    expect(($this->ids)(($this->list)()))->toBe([$newest, ...$tied]);
});

it('pages by keyset, forward and back, with no gap and no overlap (I-140)', function (): void {
    $newest = [];

    foreach (range(1, 7) as $n) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')->addMinutes($n));
        array_unshift($newest, actvRow($this->eze));
    }

    $one = ($this->list)(['perPage' => 3]);
    $two = ($this->list)(['perPage' => 3, 'after' => $one['older']]);
    $three = ($this->list)(['perPage' => 3, 'after' => $two['older']]);
    $backTwo = ($this->list)(['perPage' => 3, 'before' => $three['newer']]);
    $backOne = ($this->list)(['perPage' => 3, 'before' => $backTwo['newer']]);

    expect(($this->ids)($one))->toBe(array_slice($newest, 0, 3))
        ->and(($this->ids)($two))->toBe(array_slice($newest, 3, 3))
        ->and(($this->ids)($three))->toBe(array_slice($newest, 6))
        ->and($one['newer'])->toBeNull()
        ->and($two['newer'])->not->toBeNull()
        ->and($three['older'])->toBeNull()
        ->and(($this->ids)($backTwo))->toBe(($this->ids)($two))
        ->and($backTwo['older'])->not->toBeNull()
        ->and(($this->ids)($backOne))->toBe(($this->ids)($one))
        ->and($backOne['newer'])->toBeNull()
        ->and([$one['from'], $one['to'], $one['total']])->toBe([1, 3, null])
        ->and([$two['from'], $two['to'], $two['total']])->toBe([4, 6, null])
        ->and([$three['from'], $three['to'], $three['total']])->toBe([7, 7, null]);
});

it('ignores a cursor that doesn\'t read as a moment and a row, and shows the newest page', function (string $case): void {
    $newest = [];

    foreach (range(1, 4) as $n) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')->addMinutes($n));
        array_unshift($newest, actvRow($this->eze));
    }

    $cursor = match ($case) {
        'text' => 'not a cursor',
        'a moment that isn\'t one' => Cursor::encode(['x', $newest[0]]),
        'an ID that isn\'t one' => Cursor::encode(['2026-11-12 10:02:00+00', 'NOTAULID']),
        default => Cursor::encode([1]),
    };

    expect(($this->ids)(($this->list)(['after' => $cursor, 'perPage' => 2])))->toBe(array_slice($newest, 0, 2))
        ->and(($this->ids)(($this->list)(['before' => $cursor, 'perPage' => 2])))->toBe(array_slice($newest, 0, 2));
})->with(['text', 'a moment that isn\'t one', 'an ID that isn\'t one', 'too few values']);

it('narrows to one person', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    $theirs = actvRow($this->ada);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:01:00', 'UTC'));
    $others = actvRow($this->eze);

    expect(($this->ids)(($this->list)(['person' => $this->ada->id])))->toBe([$theirs])
        ->and(($this->ids)(($this->list)(['person' => $this->eze->id])))->toBe([$others])
        ->and(($this->list)(['person' => 999_999])['rows'])->toBe([]);
});

it('narrows to one task', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    $tidy = actvRow($this->eze);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:01:00', 'UTC'));
    $outline = actvRow($this->eze, ['task' => 'outline', 'variant' => null]);

    expect(($this->ids)(($this->list)(['task' => 'outline'])))->toBe([$outline])
        ->and(($this->ids)(($this->list)(['task' => 'tidy'])))->toBe([$tidy])
        ->and(($this->list)(['task' => 'describe'])['rows'])->toBe([]);
});

it('lists a month\'s rows between its first instant and the next month\'s, the month the total counts (I-320)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 23:59:59.999999', 'UTC'));
    $october = actvRow($this->eze);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'));
    $first = actvRow($this->eze);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:59:59.999999', 'UTC'));
    $last = actvRow($this->eze);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'));
    $december = actvRow($this->eze);

    expect(($this->ids)(($this->list)(['month' => '2026-11-01'])))->toBe([$last, $first])
        ->and(($this->ids)(($this->list)(['month' => '2026-10-01'])))->toBe([$october])
        ->and(($this->ids)(($this->list)(['month' => '2026-12-01'])))->toBe([$december])
        ->and(app(AiSpend::class)->month(CarbonImmutable::parse('2026-11-15', 'UTC'))->requests)->toBe(2);
});

it('narrows by person, task and month together, and pages inside the narrowing', function (): void {
    $wanted = [];

    foreach (range(1, 4) as $n) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')->addMinutes($n));
        array_unshift($wanted, actvRow($this->ada, ['task' => 'outline', 'variant' => null]));
        actvRow($this->ada);
        actvRow($this->eze, ['task' => 'outline', 'variant' => null]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    actvRow($this->ada, ['task' => 'outline', 'variant' => null]);

    $request = ['person' => $this->ada->id, 'task' => 'outline', 'month' => '2026-11-01', 'perPage' => 3];
    $one = ($this->list)($request);
    $two = ($this->list)([...$request, 'after' => $one['older']]);

    expect(($this->ids)($one))->toBe(array_slice($wanted, 0, 3))
        ->and(($this->ids)($two))->toBe(array_slice($wanted, 3))
        ->and($two['older'])->toBeNull();
});

it('says why a request failed in Teme\'s words, and shows nothing else about the failure', function (string $type): void {
    $failure = AiFailureType::from($type);

    actvRow($this->eze, [
        'outcome' => $failure === AiFailureType::Refused ? AiOutcome::Refused : AiOutcome::Failed,
        'failure' => $failure,
        'usage' => new AiUsage,
        'costMicrounits' => null,
    ]);

    $row = ($this->list)()['rows'][0];

    expect($row['why'])->toBe($failure->sentence())
        ->and($row['outcome']['key'])->toBe($failure === AiFailureType::Refused ? 'refused' : 'failed');
})->with(['unavailable', 'rate-limited', 'refused', 'invalid-output', 'timeout']);

it('names what a request was about: the post, the post in the trash, the image, or that it has gone', function (): void {
    $trashed = lifecycleTrashedDaysAgo($this->eze, 'Old news', 2);
    $image = mediaUpload($this->eze, mediaJpeg(), 'Harbour.jpg');
    $gone = strtolower((string) Str::ulid());

    foreach ([
        [$this->item->id, AiSubjectType::Content],
        [$trashed->id, AiSubjectType::Content],
        [$image->id, AiSubjectType::Media],
        [$gone, AiSubjectType::Content],
        [strtoupper($gone), AiSubjectType::Media],
    ] as $n => [$subjectId, $subjectType]) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')->addMinutes($n));
        actvRow($this->eze, ['subjectId' => $subjectId, 'subjectType' => $subjectType, 'task' => $subjectType === AiSubjectType::Media ? 'describe' : 'tidy']);
    }

    $rows = ($this->list)()['rows'];

    expect(array_column($rows, 'subject'))->toBe([
        ['kind' => 'media', 'id' => strtoupper($gone), 'title' => null, 'kindLabel' => 'Image', 'state' => 'gone'],
        ['kind' => 'content', 'id' => $gone, 'title' => null, 'kindLabel' => 'Item', 'state' => 'gone'],
        ['kind' => 'media', 'id' => $image->id, 'title' => $image->name, 'kindLabel' => 'Image', 'state' => 'present'],
        ['kind' => 'content', 'id' => $trashed->id, 'title' => 'Old news', 'kindLabel' => 'Post', 'state' => 'trashed'],
        ['kind' => 'content', 'id' => $this->item->id, 'title' => 'Harbour news', 'kindLabel' => 'Post', 'state' => 'present'],
    ])
        ->and(array_column($rows, 'task'))->toContain(['key' => 'describe', 'label' => 'Describe']);
});

it('shows a person who was removed with no name', function (): void {
    actvRow($this->ada);
    $this->ada->delete();

    expect(($this->list)()['rows'][0]['person'])->toBe(['id' => null, 'name' => null]);
});

it('keeps a cost that is unknown apart from one that is none (I-1302)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    actvRow($this->eze, ['model' => 'fake-unpriced', 'costMicrounits' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:01:00', 'UTC'));
    actvRow($this->eze, ['outcome' => AiOutcome::Failed, 'failure' => AiFailureType::Unavailable, 'usage' => new AiUsage, 'costMicrounits' => null]);

    expect(array_column(($this->list)()['rows'], 'costMicrounits'))->toBe([0, null]);
});

it('reads an option\'s key in words', function (string $key, string $words): void {
    expect(AiActivityList::words($key))->toBe($words);
})->with([
    'one word' => ['shorter', 'Shorter'],
    'several' => ['fix-spelling-and-grammar', 'Fix spelling and grammar'],
    'two' => ['more-formal', 'More formal'],
]);

it('finds one request by its ID, though it is far from the first page, and none that isn\'t there', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    $oldest = actvRow($this->eze);

    foreach (range(1, 3) as $n) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')->addMinutes($n));
        actvRow($this->eze);
    }

    $found = app(AiActivityList::class)->find($oldest);

    expect(($this->ids)(($this->list)(['perPage' => 1])))->not->toContain($oldest)
        ->and($found['id'] ?? null)->toBe($oldest)
        ->and($found['task'] ?? null)->toBe(['key' => 'tidy', 'label' => 'Tidy'])
        ->and(app(AiActivityList::class)->find(strtolower((string) Str::ulid())))->toBeNull()
        ->and(app(AiActivityList::class)->find('not an id'))->toBeNull();
});

it('pages with no offset, counting only for the range the screen names', function (): void {
    $image = mediaUpload($this->eze, mediaJpeg(), 'Harbour.jpg');

    foreach (range(1, 30) as $n) {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')->addMinutes($n));
        actvRow($n % 2 === 0 ? $this->eze : $this->ada, $n % 3 === 0
            ? ['subjectId' => $image->id, 'subjectType' => AiSubjectType::Media]
            : ['subjectId' => $this->item->id]);
    }

    // Warm what the first call would otherwise read once (the permission cache, the registry).
    ($this->list)(['perPage' => 20]);

    $one = null;
    $first = planStatements(function () use (&$one): void {
        $one = ($this->list)(['perPage' => 20]);
    });
    $second = planStatements(fn () => ($this->list)(['perPage' => 20, 'after' => $one['older']]));
    $sql = strtolower(implode("\n", array_column([...$first, ...$second], 'query')));

    expect($one['rows'])->toHaveCount(20)
        ->and([$one['from'], $one['to'], $one['total']])->toBe([1, 20, null])
        ->and(count($first))->toBe(4)
        ->and(count($second))->toBe(5)
        ->and($sql)->not->toContain('offset')
        // One ahead count on the second page only; no full filtered total.
        ->and(substr_count($sql, 'count('))->toBe(1);
});

it('answers a page by the index on the moment and the ID, with no sort, after a cursor too (I-140)', function (): void {
    ($this->fill)();
    $cursor = ($this->list)(['perPage' => 50])['older'];

    foreach ([['perPage' => 50], ['perPage' => 50, 'after' => $cursor], ['perPage' => 50, 'before' => $cursor]] as $request) {
        $plan = ($this->plan)($request);

        expect($plan)->toContain('ai_activity_created_index')
            ->not->toContain('Seq Scan')
            ->not->toMatch('/(^|->\s+)Sort\s/m');
    }
});

it('answers one person\'s page by the index on the person and the moment, tied moments sorted by ID alone', function (): void {
    ($this->fill)();

    $plan = ($this->plan)(['person' => $this->ada->id, 'perPage' => 50]);

    expect($plan)->toContain('ai_activity_user_index')
        ->not->toContain('Seq Scan')
        ->not->toMatch('/(^|->\s+)Sort\s/m');
});

it('answers one task\'s page by the index on the task and the moment', function (): void {
    ($this->fill)();

    $plan = ($this->plan)(['task' => 'outline', 'perPage' => 50]);

    expect($plan)->toContain('ai_activity_task_index')
        ->not->toContain('Seq Scan')
        ->not->toMatch('/(^|->\s+)Sort\s/m');
});

it('answers a month\'s page from the index on the moment, as a range', function (): void {
    ($this->fill)();

    $plan = ($this->plan)(['month' => AiSpend::monthOf(now()), 'perPage' => 50]);

    expect($plan)->toContain('ai_activity_created_index')
        ->and($plan)->toMatch('/Index Cond:.*created_at/')
        ->not->toContain('Seq Scan')
        ->not->toMatch('/(^|->\s+)Sort\s/m');
});

it('holds a page to 1 to 200 rows', function (): void {
    actvFiller($this->eze, 205);

    $big = ($this->list)(['perPage' => 500]);

    expect($big['rows'])->toHaveCount(200)
        ->and($big['older'])->not->toBeNull()
        ->and(($this->list)(['perPage' => 0])['rows'])->toHaveCount(1);
});
