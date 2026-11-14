<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('adds a nullable parent and a position that defaults to 0', function (): void {
    $columns = collect(DB::select(
        "SELECT column_name, data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'contents' AND column_name IN ('parent_id', 'position')",
    ))->keyBy('column_name');

    expect($columns['parent_id']->data_type)->toBe('character')
        ->and((int) $columns['parent_id']->character_maximum_length)->toBe(26)
        ->and($columns['parent_id']->is_nullable)->toBe('YES')
        ->and($columns['position']->data_type)->toBe('integer')
        ->and($columns['position']->is_nullable)->toBe('NO')
        ->and((string) $columns['position']->column_default)->toBe('0');
});

it('sets children free when their parent row is deleted another way', function (): void {
    $constraint = DB::selectOne("SELECT confdeltype, convalidated FROM pg_constraint WHERE conname = 'contents_parent_fk'");

    expect($constraint->confdeltype)->toBe('n')
        ->and($constraint->convalidated)->toBeTrue();

    $parent = lifecycleDraft($this->editor, 'Parent', 'page');
    $child = lifecycleDraft($this->editor, 'Child', 'page');
    DB::table('contents')->where('id', $child->id)->update(['parent_id' => $parent->id]);

    // contents and content_revisions point at each other: clear the parent's
    // pointers first, as DeleteContentPermanently does.
    DB::table('contents')->where('id', $parent->id)->update(['published_revision_id' => null, 'working_revision_id' => null]);
    DB::table('contents')->where('id', $parent->id)->delete();

    expect(DB::table('contents')->where('id', $child->id)->value('parent_id'))->toBeNull();
});

it('refuses an item as its own parent', function (): void {
    $page = lifecycleDraft($this->editor, 'Alone', 'page');

    try {
        DB::table('contents')->where('id', $page->id)->update(['parent_id' => $page->id]);
        $this->fail('The row was written.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514');
    }
});

it('builds the parent index as written', function (): void {
    $index = (string) DB::table('pg_indexes')->where('tablename', 'contents')->where('indexname', 'contents_parent')->value('indexdef');

    expect($index)->toContain('(parent_id, "position", id)')
        ->toContain('WHERE (parent_id IS NOT NULL)');
});
