<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Jobs\BringBackPageParents;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Tracking\PageParentsJobKind;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Tree\ImportedPages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    importFake();
    $this->source = importSource();
    $this->editor = User::factory()->create()->assignRole('editor');
});

/**
 * Runs the command, reading its output once (lesson B-339): its exit code and its lines.
 *
 * @param  array<string, mixed>  $options
 *
 * @return array{0: int, 1: list<string>}
 */
function ppcRun(string $address, array $options = []): array
{
    $code = Artisan::call('teme:import:page-parents', ['address' => $address, ...$options]);

    return [$code, array_values(array_filter(array_map(trim(...), explode("\n", Artisan::output())), fn (string $line): bool => $line !== ''))];
}

/**
 * Runs every step the fake queue holds, as a worker would, the next ones included.
 */
function ppcWork(): void
{
    $ran = 0;

    while (($jobs = Queue::pushed(BringBackPageParents::class)->values())->count() > $ran) {
        app()->forgetScopedInstances();
        app()->call([$jobs[$ran++], 'handle']);
    }
}

/**
 * Every page's place, every address, the jobs and the map, to show nothing changed.
 *
 * @return list<mixed>
 */
function ppcState(): array
{
    return [
        DB::table('contents')->orderBy('id')->get(['id', 'parent_id', 'position'])->all(),
        DB::table('paths')->orderBy('path')->get()->all(),
        DB::table('tracked_jobs')->orderBy('id')->get(['id', 'kind'])->all(),
        DB::table('import_map')->orderBy('id')->pluck('facts')->all(),
    ];
}

it('starts bringing back page parents as the operator, and the queue puts each page back', function (): void {
    Queue::fake();
    $pages = ImportedPages::import($this->source, $this->editor, [10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0]]);

    [$code, $lines] = ppcRun('https://old.example');
    $job = DB::table('tracked_jobs')->where('kind', PageParentsJobKind::KEY)->sole();

    expect($code)->toBe(0)
        ->and($lines)->toBe(["Started bringing back the page parents of {$this->source->address}. A queue worker carries it out: Tools › Jobs shows how it's going."])
        ->and($job->starter)->toBe('operator')
        ->and($job->started_by)->toBeNull();
    Queue::assertPushed(BringBackPageParents::class, 1);

    app()->instance(Elevation::class, new DeniedElevation);
    ppcWork();
    $response = addrGet('/team/');

    expect($pages[11]->refresh()->parent_id)->toBe($pages[10]->id)
        ->and(addrCurrent('content', $pages[11]->id))->toBe('about/team')
        ->and($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/about/team/');
});

it('refuses an address Teme doesn\'t know, and writes nothing', function (): void {
    Queue::fake();
    ImportedPages::import($this->source, $this->editor, [10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0]]);
    $before = ppcState();

    [$code, $lines] = ppcRun('elsewhere.example');

    expect($code)->toBe(1)
        ->and($lines)->toBe(['Teme doesn’t know elsewhere.example. Connect it first, in Tools › Import or with php artisan teme:import elsewhere.example --user=NAME.'])
        ->and(ppcState())->toEqual($before);
    Queue::assertNothingPushed();
});

it('refuses while an import of the site is running, and writes nothing', function (): void {
    Queue::fake();
    ImportedPages::import($this->source, $this->editor, [10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0]]);
    scElevated(fn () => app(StartImportRun::class)->execute(new StartImportRunInput($this->source->id, ImportRunKind::Full, ['pause_ms' => 0])));
    $before = ppcState();
    $queued = count(Queue::pushedJobs());

    [$code, $lines] = ppcRun('https://old.example');

    expect($code)->toBe(1)
        ->and($lines)->toBe(["An import of {$this->source->address} is running. Bring back page parents once it ends."])
        ->and(ppcState())->toEqual($before)
        ->and(count(Queue::pushedJobs()))->toBe($queued);
    Queue::assertNotPushed(BringBackPageParents::class);
});

it('refuses a site with nothing to bring back, and writes nothing', function (): void {
    Queue::fake();
    ImportedPages::import($this->source, $this->editor, [10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 0, 1]]);
    $before = ppcState();

    [$code, $lines] = ppcRun('https://old.example');

    expect($code)->toBe(1)
        ->and($lines)->toBe(["No page imported from {$this->source->address} sat under another page, so there's nothing to bring back."])
        ->and(ppcState())->toEqual($before);
    Queue::assertNothingPushed();
});

it('lists the pages that stayed where they are, with why', function (): void {
    Queue::fake();
    ImportedPages::import($this->source, $this->editor, [10 => ['About', 'about', 0, 0], 11 => ['Team', 'team', 10, 0]]);

    expect(ppcRun('https://old.example', ['--notes' => true]))->toBe([0, ['No page stayed where it was.']]);

    ImportedPages::import($this->source, $this->editor, [20 => ['Stray', 'stray', 99, 0]]);
    ppcRun('https://old.example');
    ppcWork();

    expect(ppcRun('https://old.example', ['--notes' => true]))->toBe([0, ['Stray: “Stray” sits under a page that wasn\'t imported, so it\'s at the top level here.']]);
});
