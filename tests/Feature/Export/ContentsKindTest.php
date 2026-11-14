<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Export\Kinds\ContentsKind;
use App\Domain\Fields\BuiltInFields;
use App\Domain\People\Credit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->line = fn (array $lines, string $id): array => collect($lines)->firstWhere('id', $id) ?? [];
    $this->stored = fn (string $revisionId, string $column): string => json_encode(json_decode((string) DB::table('content_revisions')->where('id', $revisionId)->value($column)), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

it('writes a published item with the revision readers see, exactly as stored, and no draft when there is none', function (): void {
    $about = addrPublished($this->editor, 'About the harbour', 'page');
    $image = mediaUpload($this->editor, mediaJpeg(), 'Harbour.jpg');
    $post = pagePublish($this->editor, 'Harbour news', [schedLinkTo($about->id, 'About us'), pageImageNode($image, strtoupper((string) Str::ulid()))], [], [], $image);

    $line = ($this->line)(ExportAs::kindLines(app(ContentsKind::class)), $post->id);

    expect($line['type'])->toBe($post->type)
        ->and($line['status'])->toBe('published')
        ->and($line['author_id'])->toBe($this->editor->id)
        ->and($line['published']['id'])->toBe($post->published_revision_id)
        ->and($line['published']['title'])->toBe('Harbour news')
        ->and(json_encode($line['published']['document'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->toBe(($this->stored)($post->published_revision_id, 'document'))
        ->and(json_encode($line['published']['fields'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->toBe(($this->stored)($post->published_revision_id, 'fields'))
        ->and($line['published']['origin'])->toBe('human')
        ->and($line['published']['created_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/')
        ->and($line['working'])->toBeNull();
});

it('keeps every reference an ID: links, images, the featured image and terms', function (): void {
    $about = addrPublished($this->editor, 'About the harbour', 'page');
    $image = mediaUpload($this->editor, mediaJpeg(), 'Harbour.jpg');
    $boats = taxonomyTerm('category', 'Boats');
    $tides = taxonomyTerm('tag', 'Tides');
    $post = pagePublish($this->editor, 'Harbour news', [schedLinkTo($about->id, 'About us'), pageImageNode($image, strtoupper((string) Str::ulid()))], [], [$tides->id, $boats->id], $image);

    $line = ($this->line)(ExportAs::kindLines(app(ContentsKind::class)), $post->id);
    $written = json_encode($line, JSON_UNESCAPED_SLASHES);

    expect($line['published']['document']->content[0]->content[0]->marks[0]->attrs->href)->toBe("teme:content/{$about->id}")
        ->and($line['published']['document']->content[1]->attrs->mediaId)->toBe($image->id)
        ->and($line['published']['fields']->{BuiltInFields::FEATURED_IMAGE}->id)->toBe($image->id)
        ->and($line['published']['terms'])->toBe([$tides->id, $boats->id])
        ->and($written)->not->toContain('http://')
        ->and($written)->not->toContain('https://');
});

it('carries an edited item’s draft beside the revision readers see, each with its own terms', function (): void {
    $boats = taxonomyTerm('category', 'Boats');
    $tides = taxonomyTerm('tag', 'Tides');
    $post = pagePublish($this->editor, 'Harbour news', [], [], [$boats->id], null);
    docSave($post, $this->editor, docWith(docText('A later word')), null, [$boats->id, $tides->id]);
    $post->refresh();

    $line = ($this->line)(ExportAs::kindLines(app(ContentsKind::class)), $post->id);

    expect($line['published']['id'])->toBe($post->published_revision_id)
        ->and($line['working']['id'])->toBe($post->working_revision_id)
        ->and($line['working']['id'])->not->toBe($line['published']['id'])
        ->and($line['published']['terms'])->toBe([$boats->id])
        ->and($line['working']['terms'])->toBe([$boats->id, $tides->id])
        ->and(json_encode($line['working']['document'], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->toBe(($this->stored)($post->working_revision_id, 'document'));
});

it('carries a draft never published, with no published revision and no address', function (): void {
    $draft = lifecycleDraft($this->editor, 'Not yet');

    $line = ($this->line)(ExportAs::kindLines(app(ContentsKind::class)), $draft->id);

    expect($line['status'])->toBe('draft')
        ->and($line['published'])->toBeNull()
        ->and($line['working']['title'])->toBe('Not yet')
        ->and($line['computed'])->toBe(['address' => null]);
});

it('gives an item readers can see its address now, marked as computed', function (): void {
    $post = addrPublished($this->editor, 'Harbour news');

    $line = ($this->line)(ExportAs::kindLines(app(ContentsKind::class)), $post->id);

    expect($line['computed']['address'])->toBe(Addresses::relativeUrl($post))
        ->and($line['computed']['address'])->toStartWith('/');
});

it('leaves items in the trash out', function (): void {
    $kept = addrPublished($this->editor, 'Kept');
    $gone = addrPublished($this->editor, 'Gone');
    pageTrash($gone, $this->editor);

    $ids = array_column(ExportAs::kindLines(app(ContentsKind::class)), 'id');

    expect($ids)->toContain($kept->id)
        ->and($ids)->not->toContain($gone->id);
});

it('reads every item exactly once, a page at a time, whatever the page size', function (): void {
    $ids = [];

    foreach (range(1, 7) as $n) {
        $ids[] = lifecycleDraft($this->editor, "Item {$n}")->id;
    }

    sort($ids, SORT_STRING);
    $byTwo = array_column(ExportAs::kindLines(app(ContentsKind::class), pageSize: 2), 'id');
    $byOne = array_column(ExportAs::kindLines(app(ContentsKind::class), pageSize: 1), 'id');
    $all = array_column(ExportAs::kindLines(app(ContentsKind::class)), 'id');

    expect($byTwo)->toBe($ids)
        ->and($byOne)->toBe($ids)
        ->and($all)->toBe($ids);
});

it('reads only the columns it names, so a secret added to the table later never leaves', function (): void {
    $post = addrPublished($this->editor, 'Harbour news');
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    ExportAs::kindLines(app(ContentsKind::class));
    $read = collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'FROM contents c'));

    expect($read)->not->toBeNull()
        ->and($read)->not->toContain('c.*')
        ->and($read)->not->toContain('SELECT *');
});

it('exports an item\'s bylines in order, and none for one that credits its owner', function (): void {
    $plain = addrPublished($this->editor, 'Plain');
    $credited = addrPublished($this->editor, 'Credited');
    $reuters = Credited::guest('Reuters', 'reuters');
    Credited::set($credited->id, [Credit::person($reuters), Credit::account((int) $this->editor->id)]);
    $lines = ExportAs::kindLines(app(ContentsKind::class));

    expect(($this->line)($lines, $plain->id)['bylines'])->toBe([])
        ->and(($this->line)($lines, $credited->id)['bylines'])->toBe([$reuters, (string) DB::table('people')->where('user_id', $this->editor->id)->value('id')])
        ->and(array_slice(array_keys(($this->line)($lines, $plain->id)), array_search('author_id', array_keys(($this->line)($lines, $plain->id)), true), 2))->toBe(['author_id', 'bylines']);
});
