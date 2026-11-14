<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function lifecycleInsertContent(string $slug, ?string $deletedAt = null, string $status = 'draft'): void
{
    DB::table('contents')->insert([
        'id' => (string) Str::ulid(),
        'type' => 'post',
        'slug' => $slug,
        'status' => $status,
        'fields' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => $deletedAt,
    ]);
}

it('allows only draft, review, scheduled and published as a status', function (string $status): void {
    lifecycleInsertContent('ok-'.$status, status: $status);

    expect(DB::table('contents')->where('status', $status)->exists())->toBeTrue();
})->with(['draft', 'review', 'scheduled', 'published']);

it('refuses trash as a status: trash is deleted_at', function (): void {
    expect(fn () => lifecycleInsertContent('bad', status: 'trash'))->toThrow(QueryException::class);
});

it('keeps slugs unique among live items only', function (): void {
    lifecycleInsertContent('about', deletedAt: now()->toDateTimeString());
    lifecycleInsertContent('about');

    expect(fn () => lifecycleInsertContent('about'))->toThrow(QueryException::class);
});

it('has a title on contents and a content_counts table', function (): void {
    expect(Schema::hasColumn('contents', 'title'))->toBeTrue()
        ->and(Schema::hasColumns('content_counts', ['type', 'bucket', 'author_id', 'count']))->toBeTrue();
});
