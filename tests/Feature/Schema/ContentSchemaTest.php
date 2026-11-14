<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates both content tables', function (): void {
    expect(Schema::hasTable('contents'))->toBeTrue()
        ->and(Schema::hasTable('content_revisions'))->toBeTrue();
});

it('versions field values on the revision, not only on the content row', function (): void {
    expect(Schema::hasColumn('content_revisions', 'fields'))->toBeTrue()
        ->and(Schema::hasColumn('content_revisions', 'document'))->toBeTrue();
});

it('stores documents as jsonb, not text', function (): void {
    $type = DB::selectOne(
        "SELECT data_type FROM information_schema.columns
         WHERE table_name = 'content_revisions' AND column_name = 'document'"
    );

    expect($type->data_type)->toBe('jsonb');
});

it('rejects an unknown revision origin', function (): void {
    $contentId = (string) Str::ulid();

    DB::table('contents')->insert([
        'id' => $contentId,
        'type' => 'post',
        'slug' => 'hello-world',
        'status' => 'draft',
        'fields' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('content_revisions')->insert([
        'id' => (string) Str::ulid(),
        'content_id' => $contentId,
        'document' => '{"type":"doc","content":[]}',
        'fields' => '{}',
        'title' => 'Hello world',
        'origin' => 'smuggled',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses two contents of the same type with the same slug', function (): void {
    $row = fn (string $slug): array => [
        'id' => (string) Str::ulid(),
        'type' => 'post',
        'slug' => $slug,
        'status' => 'draft',
        'fields' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('contents')->insert($row('duplicate'));

    expect(fn () => DB::table('contents')->insert($row('duplicate')))
        ->toThrow(QueryException::class);
});
