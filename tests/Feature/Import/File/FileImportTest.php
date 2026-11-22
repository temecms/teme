<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\AdoptImportedTerm;
use App\Domain\Import\Operations\Input\AdoptImportedTermInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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
    Http::fake(fn (Request $request): ?PromiseInterface => fileOldSite($request));
    $this->companion = importFake();
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The old site's uploaded files and its empty redirect stores, for
 * Http::fake(); null for anything else, which the fake companion answers.
 */
function fileOldSite(Request $request): ?PromiseInterface
{
    $url = parse_url($request->url());
    $path = (string) ($url['path'] ?? '/');

    if (($url['host'] ?? '') !== FakeCompanion::HOST) {
        return null;
    }

    if (str_starts_with($path, '/wp-content/uploads/')) {
        return str_ends_with($path, '.pdf')
            ? Http::response("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 200, ['Content-Type' => 'application/pdf'])
            : Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']);
    }

    if ($path === '/wp-json/teme/v1/redirects') {
        parse_str((string) ($url['query'] ?? ''), $query);

        return Http::response(match ((string) ($query['source'] ?? '')) {
            'yoast_premium' => ['api' => 1, 'plain' => null, 'regex' => null],
            'simple_301' => ['api' => 1, 'rules' => null],
            default => ['api' => 1, 'limit' => 500, 'next' => null, 'items' => []],
        });
    }

    return null;
}

/**
 * A document without its nodes' IDs, which each import makes anew.
 */
function fileWithoutIds(mixed $node): mixed
{
    if (! is_array($node)) {
        return $node;
    }

    unset($node['attrs']['id']);

    if (($node['attrs'] ?? null) === []) {
        unset($node['attrs']);
    }

    return array_map(fileWithoutIds(...), $node);
}

/**
 * What $from holds that $in doesn't, by list: an item by its type and slug,
 * a term by its taxonomy and slug.
 *
 * @param  array<string, list<mixed>>  $from
 * @param  array<string, list<mixed>>  $in
 *
 * @return array<string, list<mixed>>
 */
function fileLeftOut(array $from, array $in): array
{
    $left = [];

    foreach ($from as $list => $entries) {
        $keys = array_map(json_encode(...), $in[$list]);
        $left[$list] = array_values(array_map(
            fn (mixed $entry): mixed => match ($list) {
                'contents' => [$entry[0], $entry[1]],
                'terms' => [$entry['taxonomy'], $entry['slug']],
                default => $entry,
            },
            array_filter($entries, fn (mixed $entry): bool => ! in_array(json_encode($entry), $keys, true)),
        ));
    }

    return $left;
}

/**
 * What an import left on this site, in words that don't depend on its
 * IDs: each item's type, slug, status, title and document; each term; each
 * media file; and the people.
 *
 * @return array<string, mixed>
 */
function fileSnapshot(): array
{
    return [
        'contents' => DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', DB::raw('coalesce(contents.published_revision_id, contents.working_revision_id)'))
            ->orderBy('contents.type')->orderBy('contents.slug')
            ->get(['contents.type', 'contents.slug', 'contents.status', 'content_revisions.title', 'content_revisions.document'])
            ->map(fn (object $row): array => [$row->type, $row->slug, $row->status, $row->title, fileWithoutIds(json_decode((string) $row->document, true))])
            ->all(),
        'terms' => DB::table('terms')->orderBy('taxonomy')->orderBy('slug')->get(['taxonomy', 'slug', 'name'])
            ->map(fn (object $row): array => (array) $row)->all(),
        'media' => DB::table('media')->orderBy('original_filename')->pluck('original_filename')->all(),
        'people' => DB::table('users')->where('email', 'like', '%@old.example')->orderBy('email')->pluck('email')->all(),
    ];
}

/**
 * A whole import of the site from its companion, whose fixtures list no
 * comments and no addresses. The run, fresh.
 */
function fileCompanionRun(FakeCompanion $companion): ImportRun
{
    $companion->items('urls', [])->items('comments', []);

    return importRun(importSource());
}

/**
 * Forgets what the companion's import brought, inside the test's own
 * transaction, so that the file's import starts from the same empty site;
 * the administrator who uploads the file, made again.
 */
function fileForget(): User
{
    DB::statement('TRUNCATE contents, content_revisions, terms, media, comments, navigations, navigation_items, paths, redirects, import_sources, import_files, tracked_jobs, users CASCADE');
    Cache::flush();

    return User::factory()->create()->assignRole('administrator');
}

/**
 * The site's export file a day on: the post Harbour news and the page About
 * retitled, and the page Our team gone.
 */
function fileNewer(): string
{
    $bytes = (string) file_get_contents(ExportFiles::path('companion-site.xml'));

    return (string) preg_replace_callback('~<item>.*?</item>~s', function (array $item): string {
        $type = preg_match('~<wp:post_type><!\[CDATA\[([a-z_]+)~', $item[0], $found) === 1 ? $found[1] : '';
        $id = preg_match('~<wp:post_id>(\d+)</wp:post_id>~', $item[0], $found) === 1 ? $found[1] : '';

        return match (true) {
            $type === 'post' && $id === '12' => str_replace('<title><![CDATA[Harbour news]]>', '<title><![CDATA[Harbour news, updated]]>', $item[0]),
            $type === 'page' && $id === '13' => str_replace('<title><![CDATA[About]]>', '<title><![CDATA[About the harbour]]>', $item[0]),
            $type === 'page' && $id === '14' => '',
            default => $item[0],
        };
    }, $bytes);
}

it('updates what changed in a newer file, lists what it no longer holds, and deletes nothing', function (): void {
    $first = FileImports::ready('companion-site.xml', $this->admin);
    FileImports::run($first);
    $contents = DB::table('contents')->whereNull('deleted_at')->count();

    $this->travel(1)->minutes();
    $newer = FileImports::ready('companion-site.xml', $this->admin, fileNewer());
    $run = FileImports::run($newer);

    $missing = DB::table('import_messages')->where('run_id', $run->id)->where('code', 'file-missing.item')->pluck('context')
        ->map(fn (string $context): array => json_decode($context, true))->all();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('contents')->whereNull('deleted_at')->count())->toBe($contents)
        ->and(DB::table('import_map')->where('run_id', $run->id)->where('object_type', 'post')->where('outcome', 'updated')->pluck('object_id')->all())
        ->toEqualCanonicalizing(['12', '13'])
        ->and($missing)->toEqual([['type' => 'post', 'id' => '14']]);
});

it('keeps one term when the site is connected live after an import from its file, and the other way round', function (string $first): void {
    $file = fn (): ImportRun => FileImports::run(FileImports::ready('companion-site.xml', $this->admin));
    $live = fn (): ImportRun => fileCompanionRun($this->companion);

    $first === 'file' ? $file() : $live();
    $terms = DB::table('terms')->orderBy('taxonomy')->orderBy('slug')->get(['id', 'taxonomy', 'slug'])->all();
    $this->travel(1)->minutes();
    $second = $first === 'file' ? $live() : $file();

    expect($second->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('import_sources')->count())->toBe(1)
        ->and(DB::table('terms')->whereIn('id', array_column($terms, 'id'))->count())->toBe(count($terms))
        ->and(DB::table('terms')->where('taxonomy', 'category')->where('slug', 'birds')->count())->toBe(1)
        ->and(DB::table('terms')->where('slug', 'like', '%-2')->pluck('slug')->all())->toBe([]);
})->with(['file first' => 'file', 'live first' => 'live']);

it('moves no term’s map row for anyone who may not import', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin);
    FileImports::run($file);
    $editor = User::factory()->create()->assignRole('editor');
    $before = DB::table('import_map')->where('object_type', 'term')->orderBy('id')->get()->toJson();

    expect(fn () => app(AdoptImportedTerm::class)->execute(new AdoptImportedTermInput((string) $file->source_id, '107', '7000', (string) $editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('import_map')->where('object_type', 'term')->orderBy('id')->get()->toJson())->toBe($before);
});
