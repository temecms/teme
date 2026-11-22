<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('local');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    importResolve([FakeCompanion::HOST => [FakeCompanion::IP]]);
    config(['teme.import.local_sources' => false]);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => str_ends_with($request->url(), '.pdf')
        ? Http::response("%PDF-1.4\n%%EOF\n", 200, ['Content-Type' => 'application/pdf'])
        : Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']));
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The fixture file whose first post links to $href on the old site.
 */
function npFile(string $href): string
{
    return str_replace('https://old.example/later-story/', $href, (string) file_get_contents(ExportFiles::path('companion-site.xml')));
}

/**
 * The plan's choices for a file.
 *
 * @return array<string, mixed>
 */
function npForm(string $fileId, string $sourceId, string $person): array
{
    return app(ImportChoices::class)->fileForm(app(ImportAnalysis::class)->read($sourceId), $fileId, $person, []);
}

it('chooses no category prefix when the file’s links reach a category straight after the site’s address, and shows real slugs', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin, npFile('https://old.example/animals/'));
    $form = npForm($file->id, (string) $file->source_id, (string) $this->admin->id);

    expect($form['categoryBase'])->toBe('')
        ->and($form['tagBase'])->toBe('tag')
        ->and($form['categoryExample'])->toBe('animals')
        ->and($form['tagExample'])->toBe('night-day');
});

it('keeps today’s guess when a link reaches a category under a prefix', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin, npFile('https://old.example/topics/animals/'));
    $form = npForm($file->id, (string) $file->source_id, (string) $this->admin->id);

    expect($form['categoryBase'])->toBe('topics');
});

it('takes an empty base as no prefix, and refuses a base that isn’t one', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $choices = app(ImportChoices::class);
    $analysis = app(ImportAnalysis::class)->read((string) $file->source_id);
    $fields = ['owner' => (string) $this->admin->id, 'pause' => '1000', 'media' => 'links', 'drafts' => '1', 'comments' => '1', 'authors' => array_fill_keys(['ada', 'grace', 'harbour', 'seo', 'reader'], 'new:author'), 'types' => [], 'menu_locations' => []];

    $kept = $choices->validatedFile([...$fields, 'category_base' => '', 'tag_base' => '/'], $analysis, $file->id);

    expect($kept['choices']['category_base'])->toBe('')
        ->and($kept['choices']['tag_base'])->toBe('')
        ->and(fn () => $choices->validatedFile([...$fields, 'category_base' => 'Top Ics', 'tag_base' => 'tag'], $analysis, $file->id))
        ->toThrow(ValidationException::class, 'Use lower-case letters, numbers and hyphens, or choose No prefix.');
});

it('gives categories and tags addresses straight after the site’s address when the old site had no prefix', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file, ['category_base' => '', 'tag_base' => '', 'media' => 'links']);
    $animals = Term::query()->where('taxonomy', 'category')->where('slug', 'animals')->sole();
    $tag = Term::query()->where('taxonomy', 'tag')->where('slug', 'night-day')->sole();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('paths')->where('target_type', 'term')->where('target_id', $animals->id)->where('is_current', true)->value('path'))->toBe('animals')
        ->and(DB::table('paths')->where('target_type', 'term')->where('target_id', $tag->id)->where('is_current', true)->value('path'))->toBe('night-day')
        ->and(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'like', 'check.%')->where('message', 'like', '%/animals/%')->exists())->toBeFalse();
});

it('stops at the address clash when a category without a prefix lands on a page Teme already has', function (): void {
    addrPublished($this->admin, 'Animals', 'page');
    $file = FileImports::ready('companion-site.xml', $this->admin);
    $run = FileImports::run($file, ['category_base' => '', 'tag_base' => 'tag', 'media' => 'links']);

    expect($run->state)->toBe(ImportRunState::Stopped)
        ->and(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'clash.address')->pluck('message')->all())
        ->toContain('/animals/ is the page "Animals" here, and the old site has the category there.');
});
