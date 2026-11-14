<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('adds the plain text, word count and kind to revisions, and the search columns to contents', function (): void {
    $searchTsv = DB::selectOne(
        "SELECT data_type FROM information_schema.columns WHERE table_name = 'contents' AND column_name = 'search_tsv'"
    );

    expect(Schema::hasColumns('content_revisions', ['plain_text', 'word_count', 'kind']))->toBeTrue()
        ->and(Schema::hasColumns('contents', ['search_text', 'search_tsv']))->toBeTrue()
        ->and($searchTsv->data_type)->toBe('tsvector');
});

it('gives a new revision the save kind and a word count of zero', function (): void {
    $revision = DB::table('content_revisions')
        ->where('id', lifecycleDraft($this->author, 'Defaults')->working_revision_id)
        ->first(['kind', 'word_count']);

    expect($revision->kind)->toBe('save')
        ->and($revision->word_count)->toBe(0);
});

it('allows each revision kind', function (string $kind): void {
    $id = lifecycleDraft($this->author, 'Kinds')->working_revision_id;

    DB::table('content_revisions')->where('id', $id)->update(['kind' => $kind]);

    expect(DB::table('content_revisions')->where('id', $id)->value('kind'))->toBe($kind);
})->with(['save', 'checkpoint', 'publish', 'restore', 'import', 'ai']);

it('refuses an unknown revision kind', function (): void {
    $id = lifecycleDraft($this->author, 'Kinds')->working_revision_id;

    expect(fn () => DB::table('content_revisions')->where('id', $id)->update(['kind' => 'autosave']))
        ->toThrow(QueryException::class);
});

it('indexes search_tsv with GIN, and content links by their target', function (): void {
    $indexes = DB::table('pg_indexes')->whereIn('tablename', ['contents', 'content_links'])->pluck('indexdef', 'indexname');

    expect($indexes['contents_search_tsv'])->toContain('USING gin (search_tsv)')
        ->and($indexes['content_links_pkey'])->toContain('(from_content_id, scope, to_content_id)')
        ->and($indexes['content_links_to_content_id_scope_index'])->toContain('(to_content_id, scope)');
});

it('keeps one link per pair and scope', function (): void {
    $from = lifecycleDraft($this->author, 'From');
    $to = lifecycleDraft($this->author, 'To');
    $row = fn (string $scope): array => ['from_content_id' => $from->id, 'to_content_id' => $to->id, 'scope' => $scope];

    DB::table('content_links')->insert([$row('working'), $row('published')]);

    expect(fn () => DB::table('content_links')->insert($row('working')))->toThrow(QueryException::class);
});

it('refuses a link scope other than working or published', function (): void {
    $from = lifecycleDraft($this->author, 'From');
    $to = lifecycleDraft($this->author, 'To');

    expect(fn () => DB::table('content_links')->insert(['from_content_id' => $from->id, 'to_content_id' => $to->id, 'scope' => 'draft']))
        ->toThrow(QueryException::class);
});

it('drops an item\'s links when the item is deleted for good', function (): void {
    $from = lifecycleDraft($this->author, 'From');
    $to = lifecycleDraft($this->author, 'To');
    DB::table('content_links')->insert(['from_content_id' => $from->id, 'to_content_id' => $to->id, 'scope' => 'working']);

    DB::table('contents')->where('id', $to->id)->delete();

    expect(DB::table('content_links')->count())->toBe(0);
});
