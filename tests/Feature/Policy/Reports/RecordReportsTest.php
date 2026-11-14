<?php

declare(strict_types=1);

use App\Domain\Site\Policy\Reports\Operations\Input\RecordPolicyReportsInput;
use App\Domain\Site\Policy\Reports\Operations\RecordPolicyReports;
use App\Domain\Site\Policy\Reports\PolicyReport;
use App\Domain\Site\Policy\Reports\PolicyReportSpool;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOn();
    $this->spool = app(PolicyReportSpool::class);
    // Reports spooled, one for each row given: [directive, blocked, path, disposition, sample, seconds ago].
    $this->spooled = function (array $rows): void {
        foreach ($rows as [$directive, $blocked, $path, $disposition, $sample, $ago]) {
            $this->spool->append(new PolicyReport($directive, $blocked, $path, $disposition, $sample, now()->getTimestamp() - $ago));
        }
    };
    // One minute's run, as the scheduler makes it; the spool is read a run after it is closed.
    $this->run = function (int ...$more): int {
        $this->app->instance(Elevation::class, new OperatorElevation);

        return app(RecordPolicyReports::class)->execute(new RecordPolicyReportsInput(...$more));
    };
});

afterEach(fn () => cacheOff($this->folder));

it('builds the table as written', function (): void {
    expect(Schema::getColumnListing('csp_reports'))->toBe(['id', 'directive', 'blocked', 'page', 'disposition', 'count', 'sample', 'first_seen_at', 'last_seen_at']);

    $row = ['directive' => 'img-src', 'blocked' => 'https://a.example', 'page' => '/', 'disposition' => 'report', 'count' => 1, 'sample' => null, 'first_seen_at' => now(), 'last_seen_at' => now()];
    DB::table('csp_reports')->insert($row);

    $indexes = collect(Schema::getIndexes('csp_reports'));

    expect($indexes->contains(fn (array $i): bool => $i['unique'] && $i['columns'] === ['directive', 'blocked', 'page']))->toBeTrue()
        ->and($indexes->contains(fn (array $i): bool => $i['columns'] === ['last_seen_at']))->toBeTrue()
        ->and(fn () => DB::transaction(fn () => DB::table('csp_reports')->insert($row)))->toThrow(QueryException::class);

    foreach ([['count' => 0, 'page' => '/zero'], ['disposition' => 'x', 'page' => '/x']] as $bad) {
        try {
            DB::transaction(fn () => DB::table('csp_reports')->insert([...$row, ...$bad]));
            $code = null;
        } catch (QueryException $e) {
            $code = $e->getCode();
        }

        expect($code)->toBe('23514');
    }
});

it('adds a run ago’s lines to one row per directive, blocked origin and page, counting them', function (): void {
    ($this->spooled)([
        ['img-src', 'https://px.example.net', '/about', 'report', null, 300],
        ['img-src', 'https://px.example.net', '/about', 'report', null, 200],
        ['img-src', 'https://px.example.net', '/about', 'report', null, 100],
        ['font-src', 'https://f.example.net', '/', 'report', null, 90],
        ['font-src', 'https://f.example.net', '/', 'report', null, 80],
    ]);

    // The first run closes the open spool; the second reads it.
    expect(($this->run)())->toBe(0)
        ->and(DB::table('csp_reports')->count())->toBe(0)
        ->and(is_file($this->spool->folder().'/'.PolicyReportSpool::OPEN))->toBeFalse()
        ->and(is_file($this->spool->folder().'/'.PolicyReportSpool::CLOSED))->toBeTrue()
        ->and(($this->run)())->toBe(5);

    $rows = DB::table('csp_reports')->orderBy('directive')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->directive)->toBe('font-src')
        ->and($rows[0]->count)->toBe(2)
        ->and($rows[1]->directive)->toBe('img-src')
        ->and($rows[1]->count)->toBe(3)
        ->and($rows[1]->page)->toBe('/about')
        ->and(now()->getTimestamp() - strtotime($rows[1]->first_seen_at))->toBe(300)
        ->and(now()->getTimestamp() - strtotime($rows[1]->last_seen_at))->toBe(100)
        ->and(is_file($this->spool->folder().'/'.PolicyReportSpool::CLOSED))->toBeFalse();

    // A later run adds to the same row.
    ($this->spooled)([['img-src', 'https://px.example.net', '/about', 'report', null, 10]]);
    ($this->run)();
    ($this->run)();

    expect(DB::table('csp_reports')->where('directive', 'img-src')->value('count'))->toBe(4);
});

it('keeps a row’s first sample and its last disposition', function (): void {
    ($this->spooled)([
        ['script-src-elem', 'inline', '/', 'report', ['sample' => 'first'], 20],
        ['script-src-elem', 'inline', '/', 'enforce', ['sample' => 'second'], 10],
    ]);
    ($this->run)();
    ($this->run)();

    $row = DB::table('csp_reports')->first();

    expect(json_decode($row->sample, true))->toBe(['sample' => 'first'])
        ->and($row->disposition)->toBe('enforce');

    ($this->spooled)([['script-src-elem', 'inline', '/', 'report', ['sample' => 'third'], 5]]);
    ($this->run)();
    ($this->run)();

    $again = DB::table('csp_reports')->first();

    expect(json_decode($again->sample, true))->toBe(['sample' => 'first'])
        ->and($again->disposition)->toBe('report')
        ->and($again->count)->toBe(3);
});

it('reads a file a failed run left first', function (): void {
    ($this->spooled)([['img-src', 'https://a.example', '/', 'report', null, 5]]);
    rename($this->spool->folder().'/'.PolicyReportSpool::OPEN, $this->spool->folder().'/'.PolicyReportSpool::READING);

    expect(($this->run)())->toBe(1)
        ->and(DB::table('csp_reports')->count())->toBe(1)
        ->and(is_file($this->spool->folder().'/'.PolicyReportSpool::READING))->toBeFalse();
});

it('skips a line that isn’t whole', function (): void {
    mkdir($this->spool->folder(), 0775, true);
    $now = now()->getTimestamp();
    file_put_contents($this->spool->folder().'/'.PolicyReportSpool::OPEN, implode("\n", [
        "img-src\thttps://a.example\t/\treport\t\t{$now}",
        "img-src\thttps://a.example\t/\treport\t",
        "made-up-src\thttps://a.example\t/\treport\t\t{$now}",
        "img-src\thttps://a.example\t/\tsideways\t\t{$now}",
        "img-src\thttps://a.example\t/\treport\t\tlater",
        'garbage',
    ])."\n");
    ($this->run)();

    expect(($this->run)())->toBe(1)
        ->and(DB::table('csp_reports')->count())->toBe(1);
});

it('lets rows not seen for 30 days go, and the least recently seen past the cap', function (): void {
    DB::table('csp_reports')->insert([
        ['directive' => 'img-src', 'blocked' => 'https://old.example', 'page' => '/', 'disposition' => 'report', 'count' => 4, 'sample' => null, 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subDays(31)],
        ['directive' => 'img-src', 'blocked' => 'https://recent.example', 'page' => '/', 'disposition' => 'report', 'count' => 4, 'sample' => null, 'first_seen_at' => now()->subDays(20), 'last_seen_at' => now()->subDays(10)],
    ]);

    // Eviction follows a run that read lines, as the 404 log's does.
    ($this->spooled)([['img-src', 'https://recent.example', '/', 'report', null, 5]]);
    ($this->run)(keep: 3);
    ($this->run)(keep: 3);

    expect(DB::table('csp_reports')->pluck('blocked')->all())->toBe(['https://recent.example']);

    ($this->spooled)(array_map(fn (int $n): array => ['img-src', "https://n{$n}.example", '/', 'report', null, 600 - $n * 60], range(1, 5)));
    ($this->run)(keep: 3);
    ($this->run)(keep: 3);

    expect(DB::table('csp_reports')->orderBy('blocked')->pluck('blocked')->all())->toBe(['https://n4.example', 'https://n5.example', 'https://recent.example']);
});

it('refuses anyone but the operator, and writes nothing', function (): void {
    $this->seed(RoleSeeder::class);
    ($this->spooled)([['img-src', 'https://a.example', '/', 'report', null, 5]]);
    ($this->run)();
    $closed = $this->spool->folder().'/'.PolicyReportSpool::CLOSED;
    $before = file_get_contents($closed);

    // A person asking, then nobody at all: the operator's elevation is not bound.
    $this->app->forgetInstance(Elevation::class);
    $person = User::factory()->create()->assignRole('administrator');

    expect(fn () => app(RecordPolicyReports::class)->execute(new RecordPolicyReportsInput(actorId: (string) $person->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecordPolicyReports::class)->execute(new RecordPolicyReportsInput))->toThrow(AuthorizationException::class)
        ->and(DB::table('csp_reports')->count())->toBe(0)
        ->and(file_get_contents($closed))->toBe($before)
        ->and(is_file($this->spool->folder().'/'.PolicyReportSpool::READING))->toBeFalse();
});

it('writes nothing to the security log', function (): void {
    $events = DB::table('security_events')->count();
    ($this->spooled)([['img-src', 'https://a.example', '/', 'report', null, 5]]);
    ($this->run)();
    ($this->run)();

    expect(DB::table('csp_reports')->count())->toBe(1)
        ->and(DB::table('security_events')->count())->toBe($events);
});

it('runs every minute, as the operator', function (): void {
    ($this->spooled)([['img-src', 'https://a.example', '/', 'report', null, 5]]);

    $this->artisan('schedule:list')->expectsOutputToContain('teme:csp:record')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'teme:csp:record'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();

    $this->artisan('teme:csp:record')->assertSuccessful();
    $this->artisan('teme:csp:record')->expectsOutput('Policy reports read: 1.')->assertSuccessful();
});

it('shows that PostgreSQL refuses a NUL in jsonb, which is why the reader keeps none', function (): void {
    $code = null;

    try {
        DB::transaction(fn () => DB::select('SELECT ?::jsonb', [json_encode(['sample' => "\0"])]));
    } catch (QueryException $e) {
        $code = $e->getCode();
    }

    expect($code)->toBe('22P05');
});

it('sets a file whose lines fail to store aside, reports it once, and goes on to the next file', function (): void {
    Exceptions::fake();
    $folder = $this->spool->folder();
    mkdir($folder, 0775, true);
    $now = now()->getTimestamp();
    // A line the reader would never write, as a hand-made file may hold: a NUL the table's jsonb refuses.
    $nul = rawurlencode((string) json_encode(['sample' => "\0"]));
    file_put_contents($folder.'/'.PolicyReportSpool::READING, "script-src-elem\tinline\t%2F\treport\t{$nul}\t{$now}\n");
    ($this->spooled)([['img-src', 'https://a.example', '/', 'report', null, 5]]);

    // The good file is closed by the first run, and read, with the bad one, by the second.
    ($this->run)();

    expect(($this->run)())->toBe(1)
        ->and(DB::table('csp_reports')->pluck('blocked')->all())->toBe(['https://a.example'])
        ->and(is_file($folder.'/'.PolicyReportSpool::READING))->toBeFalse()
        ->and($this->spool->badFiles())->toHaveCount(1);

    Exceptions::assertReportedCount(1);
});

it('keeps at most three set-aside files, the oldest deleted', function (): void {
    Exceptions::fake();
    $folder = $this->spool->folder();
    mkdir($folder, 0775, true);
    $now = now()->getTimestamp();
    $nul = rawurlencode((string) json_encode(['sample' => "\0"]));

    foreach (range(1, 5) as $n) {
        file_put_contents($folder.'/'.PolicyReportSpool::READING, "script-src-elem\tinline\t%2F\treport\t{$nul}\t{$now}\n");
        ($this->run)();
        $this->travel(2)->seconds();
    }

    expect($this->spool->badFiles())->toHaveCount(3);
});

it('loses no file when a run rolls back after reading it', function (): void {
    ($this->spooled)([['img-src', 'https://a.example', '/', 'report', null, 5]]);
    ($this->run)();

    try {
        DB::transaction(function (): void {
            ($this->run)();

            throw new RuntimeException('The surrounding work failed.');
        });
    } catch (RuntimeException) {
        // The rows rolled back with it; the file must still be there.
    }

    $left = $this->spool->folder().'/'.PolicyReportSpool::READING;

    expect(DB::table('csp_reports')->count())->toBe(0)
        ->and(is_file($left))->toBeTrue();

    expect(($this->run)())->toBe(1)
        ->and(DB::table('csp_reports')->count())->toBe(1)
        ->and(is_file($left))->toBeFalse();
});
