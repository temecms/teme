<?php

declare(strict_types=1);

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Import\Source\CompanionClient;
use App\Domain\Import\Source\FileReader;
use App\Domain\Import\Source\SourceReaders;
use App\Domain\Import\Source\SourceRefused;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Storage::fake('local');
    app()->instance(ContentBlocks::class, ExportFiles::blocks());
    // No phase runs: these tests start runs and look at what they read.
    Bus::fake();
});

it('reads a list from its start, the authors a file names only on its items first, a page at a time', function (): void {
    $file = FileImports::ready('wxr-1.0.xml', $this->admin);
    $reader = new FileReader($file->id, (string) $file->source_id);

    $all = DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'users')->orderBy('object_id')->pluck('object_id')->map(fn (mixed $id): int => (int) $id)->all();
    $first = $reader->page('users', [], 0, 1);
    $second = $reader->page('users', [], (int) $first->nextAfterId, 100);

    expect($all[0])->toBeLessThan(0)
        ->and(array_column($first->items, 'id'))->toBe([$all[0]])
        ->and($first->nextAfterId)->toBe($all[0])
        ->and(array_column($second->items, 'id'))->toBe(array_slice($all, 1))
        ->and($second->nextAfterId)->toBeNull();
});

it('brings only the types and statuses asked for, and no drafts or comments when the choices leave them out', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $site = (string) $file->source_id;
    $query = ['types' => 'post,page', 'statuses' => 'publish,future,draft,pending,private,trash'];

    $everything = (new FileReader($file->id, $site))->page('content', $query, 0, 100)->items;
    $drafted = FileImports::ready('edge-cases.xml', $this->admin);
    $withDrafts = (new FileReader($drafted->id, (string) $drafted->source_id))->page('content', $query, 0, 100)->items;
    $published = (new FileReader($drafted->id, (string) $drafted->source_id, ['drafts' => false]))->page('content', $query, 0, 100)->items;
    $pages = (new FileReader($file->id, $site))->page('content', ['types' => 'page', 'statuses' => 'publish'], 0, 100)->items;

    expect(array_unique(array_column($everything, 'type')))->toEqualCanonicalizing(['post', 'page'])
        ->and(array_column($withDrafts, 'status'))->toEqualCanonicalizing(['draft', 'trash'])
        ->and($published)->toBe([])
        ->and(array_unique(array_column($pages, 'type')))->toBe(['page'])
        ->and((new FileReader($file->id, $site))->page('comments', [], 0, 100)->items)->not->toBe([])
        ->and((new FileReader($file->id, $site, ['comments' => false]))->page('comments', [], 0, 100)->items)->toBe([]);
});

it('answers the routes a file can’t hold as a site without them', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $reader = new FileReader($file->id, (string) $file->source_id, ['category_base' => 'topics']);

    expect($reader->whole('languages'))->toBe(['active' => false])
        ->and($reader->whole('settings')['options'])->toBe(['permalink_structure' => '/%postname%/', 'category_base' => 'topics', 'tag_base' => ''])
        ->and($reader->whole('menus')['assigned_locations'])->toBe(['menu-3' => 3, 'menu-4' => 4])
        ->and($reader->whole('analyze')['report']['sections']['menus']['assigned_locations'])->toBe(['menu-3' => 3, 'menu-4' => 4])
        ->and(fn () => $reader->whole('redirects'))->toThrow(SourceRefused::class);
});

it('starts a run of a site never connected live from its file, which reads the file', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file, ['category_base' => 'topics']);

    expect($run->file_id)->toBe($file->id)
        ->and($run->state)->toBe(ImportRunState::Running)
        ->and(app(SourceReaders::class)->for($run))->toBeInstanceOf(FileReader::class)
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'urls-term')->whereRaw("payload->>'url' = ?", ['https://old.example/topics/animals/owls/'])->exists())->toBeTrue()
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'urls-post')->whereRaw("payload->>'url' = ?", ['https://old.example/harbour-news/'])->exists())->toBeTrue();
});

it('reads the companion for a run without a file, as before', function (): void {
    importFake();
    $source = importSource();
    $run = importRun($source);

    expect($run->file_id)->toBeNull()
        ->and(app(SourceReaders::class)->for($run->load('source')))->toBeInstanceOf(CompanionClient::class);
});

it('refuses to start from a file of another site, or one not read', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $unread = FileImports::uploaded('companion-site.xml', $this->admin);
    $start = fn (string $fileId, ImportRunKind $kind = ImportRunKind::Full) => scElevated(fn (): array => app(StartImportRun::class)->execute(
        new StartImportRunInput((string) $file->source_id, $kind, ['pause_ms' => 0], [], null, $fileId),
    ));

    expect(fn () => $start($unread->id))->toThrow(ValidationException::class, 'Teme no longer has that export file. Upload it again.')
        ->and(fn () => $start($file->id, ImportRunKind::CatchUp))->toThrow(ValidationException::class)
        ->and(ImportRun::query()->count())->toBe(0);
});

it('opens a site read from a file at its plan, and at Connect once the file is gone', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $site = (string) $file->source_id;
    $screen = app(ImportScreen::class);

    expect($screen->step($site))->toBe('plan')
        ->and($screen->site($site))->toMatchArray(['connected' => false, 'userName' => null, 'connectedAt' => null])
        ->and($screen->site($site)['file'])->toMatchArray(['id' => $file->id, 'name' => 'companion-site.xml']);

    DB::table('import_files')->where('id', $file->id)->update(['deleted_at' => now()]);

    expect($screen->step($site))->toBe('connect')
        ->and($screen->site($site)['file'])->toBeNull();
});

it('opens at the plan again when a newer file is read after an import finished', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file);
    DB::table('import_runs')->where('id', $run->id)->update(['state' => ImportRunState::Finished->value, 'phase_token' => null, 'ended_at' => now()]);
    $screen = app(ImportScreen::class);

    expect($screen->step((string) $file->source_id))->toBe('report');

    $this->travel(1)->minutes();
    FileImports::ready('companion-site.xml', $this->admin);

    expect($screen->step((string) $file->source_id))->toBe('plan');
});
