<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Media\MediaDisks;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->editor = User::factory()->create()->assignRole('editor');

    // What the normaliser keeps of each address, in order; $links gets what it says it changed.
    $this->kept = function (array $hrefs, ?array &$links = null): array {
        $clean = DocumentNormaliser::normalise(docWith(...array_map(fn (string $href): array => guardLink($href, 'words'), $hrefs)), $links);

        return array_map(fn (array $block): ?string => $block['content'][0]['marks'][0]['attrs']['href'] ?? null, $clean['content']);
    };
});

it('stores a link to a category’s or a tag’s address by the term’s ID, keeping its place and dropping its query', function (): void {
    $harbour = typesTerm($this->editor, 'category', 'Harbour');
    $walks = typesTerm($this->editor, 'tag', 'Harbour walks');
    $category = '/'.addrCurrent('term', $harbour->id).'/';
    $tag = '/'.addrCurrent('term', $walks->id).'/';

    expect(($this->kept)(["{$category}?utm_source=mail#latest", $tag, rtrim($tag, '/')]))
        ->toBe(["teme:term/{$harbour->id}#latest", "teme:term/{$walks->id}", "teme:term/{$walks->id}"]);
});

it('stores a link to an author’s address by the person’s ID', function (): void {
    $ada = lkAuthor('Ada Harbour');

    expect(($this->kept)(['/'.addrCurrent('user', (string) $ada->id).'/']))->toBe(["teme:user/{$ada->id}"]);
});

it('follows a category’s old address to the category, as an article’s is followed (I-1670)', function (): void {
    $harbour = typesTerm($this->editor, 'category', 'Harbour');
    $old = '/'.addrCurrent('term', $harbour->id).'/';
    app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Harbour', 'the-harbour', null, null, (string) $this->editor->id, $harbour->id));

    expect(addrCurrent('term', $harbour->id))->toBe('category/the-harbour')
        ->and(($this->kept)([$old]))->toBe(["teme:term/{$harbour->id}"]);
});

it('keeps as paths the pages with no ID: the home page, a feed, a later page of a list, a date archive and a path nothing has (I-1669)', function (): void {
    $harbour = typesTerm($this->editor, 'category', 'Harbour');
    $category = '/'.addrCurrent('term', $harbour->id);
    $paths = ['/', '/feed/', "{$category}/page/2/", "{$category}/feed/", '/2026/10/', '/no-such-page/'];
    $links = [];

    expect(($this->kept)($paths, $links))->toBe($paths)
        ->and($links)->toBe([]);
});

it('stores a link to a library file’s address by the file’s ID, whichever size or version it names', function (): void {
    $report = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');
    $image = lkImage($this->editor);
    $base = rtrim(MediaDisks::public()->url(''), '/');

    expect(($this->kept)([
        "{$base}/{$report->id}/v1/annual-report.pdf#page=2",
        "{$base}/{$report->id}/v3/annual-report.pdf?download=1",
        "{$base}/".strtolower($image->id).'/v1/harbour-w640.webp',
        "{$base}/01K5Z9P2QH7N3X4C8V6B0M1T2T/v1/gone.pdf",
        "{$base}/{$report->id}/annual-report.pdf",
    ]))->toBe([
        "teme:media/{$report->id}#page=2",
        "teme:media/{$report->id}",
        "teme:media/{$image->id}",
        "{$base}/01K5Z9P2QH7N3X4C8V6B0M1T2T/v1/gone.pdf",
        "{$base}/{$report->id}/annual-report.pdf",
    ]);
});

it('stores a file by its ID where the media disk is served from another host, and leaves that host’s other addresses alone', function (): void {
    Storage::fake('media', ['url' => 'https://files.example/media']);
    $report = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');
    $lookups = 0;

    DB::listen(function (QueryExecuted $query) use (&$lookups): void {
        $lookups += str_contains($query->sql, 'from "media"') ? 1 : 0;
    });

    expect(($this->kept)([
        "https://files.example/media/{$report->id}/v1/annual-report.pdf#page=2",
        'https://files.example/other/annual-report.pdf',
        'https://example.org/storage/media/01K5Z9P2QH7N3X4C8V6B0M1T2T/v1/x.pdf',
    ]))->toBe([
        "teme:media/{$report->id}#page=2",
        'https://files.example/other/annual-report.pdf',
        'https://example.org/storage/media/01K5Z9P2QH7N3X4C8V6B0M1T2T/v1/x.pdf',
    ])->and($lookups)->toBe(1);
});

it('stores this site’s own full address of a category, an author or a file by ID, never with the site’s host (rule 9)', function (): void {
    // The rules read config('app.url') on each call, so this holds whatever the test environment says.
    config(['app.url' => 'https://harbour.example']);
    Storage::fake('media', ['url' => 'https://harbour.example/storage/media']);
    $harbour = typesTerm($this->editor, 'category', 'Harbour');
    $ada = lkAuthor('Ada Harbour');
    $report = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');
    $links = [];
    $stored = ["teme:term/{$harbour->id}", "teme:user/{$ada->id}", "teme:media/{$report->id}"];

    expect(($this->kept)([
        'https://harbour.example/'.addrCurrent('term', $harbour->id).'/',
        'https://www.harbour.example/'.addrCurrent('user', (string) $ada->id).'/',
        "https://harbour.example/storage/media/{$report->id}/v1/annual-report.pdf",
    ], $links))->toBe($stored)
        ->and(array_column($links, 'to'))->toBe($stored);
});

it('stores pasted links to a category and a file by ID through the Operation', function (): void {
    $harbour = typesTerm($this->editor, 'category', 'Harbour');
    $report = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');
    $base = rtrim(MediaDisks::public()->url(''), '/');

    $stored = docSave(lifecycleDraft($this->editor, 'Weekend guide'), $this->editor, docWith(
        guardLink('/'.addrCurrent('term', $harbour->id).'/#latest', 'the harbour'),
        guardLink("{$base}/{$report->id}/v1/annual-report.pdf", 'the report'),
    ))->workingRevision->document;

    expect($stored['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe("teme:term/{$harbour->id}#latest")
        ->and($stored['content'][1]['content'][0]['marks'][0]['attrs']['href'])->toBe("teme:media/{$report->id}");
});
