<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Addresses\WordPressAddresses;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\Content\PageParentsStep;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    ContentImports::person($this->source->id, $this->author, 3);
    // The person's row comes from a run that stopped: this is the site's first import.
    DB::table('import_runs')->where('source_id', $this->source->id)->update(['state' => ImportRunState::Stopped->value, 'stop_reason' => 'cancelled']);
});

/**
 * WordPress's page $id, under $parent, at $path.
 *
 * @return array<string, mixed>
 */
function ppsPage(int $id, string $slug, string $title, int $parent, string $path, array $fields = []): array
{
    return ContentImports::wpItem($id, ['type' => 'page', 'slug' => $slug, 'title' => $title, 'parent' => $parent, 'permalink' => "https://old.example/{$path}/", ...$fields]);
}

/**
 * The content phase and the page-parents step, run to their end.
 *
 * @param  list<array<string, mixed>>  $items
 */
function ppsRun(FakeCompanion $companion, ImportSource $source, array $items, ImportRunKind $kind = ImportRunKind::Full): ImportRun
{
    $companion->items('content', $items);
    importPhaseAlone(app(ContentPhase::class), app(PageParentsStep::class));

    return importRun($source, null, $kind);
}

function ppsItem(string $sourceId, int $wordpressId): Content
{
    return Content::withTrashed()->findOrFail(ContentImports::row($sourceId, $wordpressId)?->teme_id);
}

/**
 * What the run said that belongs to no one object, by code.
 *
 * @return array<string, string>
 */
function ppsSaid(ImportRun $run): array
{
    return ImportMessageRecord::query()->where('run_id', $run->id)->whereNull('map_id')->pluck('message', 'code')->all();
}

it('maps pages to /{parent}/{slug}/ from WordPress\'s permalinks', function (): void {
    expect(WordPressAddresses::plan('/%postname%/', '', '', false, 'post', 'page', 'category', 'tag')['values']['pattern_page'])->toBe('/{parent}/{slug}/')
        ->and(WordPressAddresses::plan('/%postname%', '', '', false, 'post', 'page', 'category', 'tag')['values']['pattern_page'])->toBe('/{parent}/{slug}');
});

it('places a new import\'s pages under their parents, each at its WordPress address', function (): void {
    ppsRun($this->companion, $this->source, [
        ppsPage(13, 'about', 'About', 0, 'about'),
        ppsPage(14, 'team', 'Team', 13, 'about/team'),
        ppsPage(15, 'people', 'People', 14, 'about/team/people'),
    ]);
    $response = addrGet('/team/');

    expect(ppsItem($this->source->id, 14)->parent_id)->toBe(ppsItem($this->source->id, 13)->id)
        ->and(addrCurrent('content', ppsItem($this->source->id, 14)->id))->toBe('about/team')
        ->and(addrCurrent('content', ppsItem($this->source->id, 15)->id))->toBe('about/team/people')
        ->and($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/about/team/');
});

it('writes no address-moved warning for a child page it will place', function (): void {
    ppsRun($this->companion, $this->source, [ppsPage(13, 'about', 'About', 0, 'about'), ppsPage(14, 'team', 'Team', 13, 'about/team')]);

    expect(ContentImports::codes(ContentImports::row($this->source->id, 14)))->not->toContain('post.address-moved');
});

it('reports the pages it couldn\'t place as the run\'s messages', function (): void {
    $run = ppsRun($this->companion, $this->source, [ppsPage(15, 'stray', 'Stray', 99, 'gone/stray')]);

    expect(ppsSaid($run))->toBe(['page.parent-missing' => '“Stray” sits under a page that wasn\'t imported, so it\'s at the top level here.'])
        ->and(ppsItem($this->source->id, 15)->parent_id)->toBeNull();
});

it('does nothing on an old site\'s catch-up and says so once', function (): void {
    ImportRun::query()->create([
        'source_id' => $this->source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Finished,
        'high_water_at' => now()->subDay(), 'started_at' => now()->subDay(), 'ended_at' => now()->subDay(),
    ]);

    $changed = ['modified_gmt' => now()->utc()->format('Y-m-d H:i:s')];
    $run = ppsRun($this->companion, $this->source, [ppsPage(13, 'about', 'About', 0, 'about', $changed), ppsPage(14, 'team', 'Team', 13, 'about/team', $changed)], ImportRunKind::CatchUp);

    expect(ppsItem($this->source->id, 14)->parent_id)->toBeNull()
        ->and(ImportMessageRecord::query()->where('run_id', $run->id)->where('code', 'page.parents-later')->pluck('message')->all())
        ->toBe(["Page parents weren't brought back for this site yet, so pages stay where they are. Run php artisan teme:import:page-parents {$this->source->address} when you're ready."]);
});

it('keeps the tree up to date on a catch-up once it is placed', function (): void {
    ppsRun($this->companion, $this->source, [
        ppsPage(13, 'about', 'About', 0, 'about'), ppsPage(14, 'team', 'Team', 13, 'about/team'), ppsPage(16, 'other', 'Other', 0, 'other'),
    ]);

    ppsRun($this->companion, $this->source, [
        ppsPage(13, 'about', 'About', 0, 'about'),
        ppsPage(14, 'team', 'Team', 16, 'other/team', ['modified_gmt' => now()->utc()->format('Y-m-d H:i:s')]),
        ppsPage(16, 'other', 'Other', 0, 'other'),
    ], ImportRunKind::CatchUp);

    expect(ppsItem($this->source->id, 14)->parent_id)->toBe(ppsItem($this->source->id, 16)->id)
        ->and(addrCurrent('content', ppsItem($this->source->id, 14)->id))->toBe('other/team');
});
