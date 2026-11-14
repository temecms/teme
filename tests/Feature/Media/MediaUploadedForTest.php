<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('keeps the item a file was uploaded for, and forgets it, keeping the file, when that item is deleted (I-553)', function (): void {
    $item = Content::factory()->create();
    $media = mediaUpload($this->admin, mediaJpeg());

    expect($media->fresh()->uploaded_for)->toBeNull();

    DB::table('media')->where('id', $media->id)->update(['uploaded_for' => $item->id]);
    expect(Media::query()->findOrFail($media->id)->uploaded_for)->toBe($item->id);

    DB::table('contents')->where('id', $item->id)->delete();

    expect(Media::query()->find($media->id))->not->toBeNull()
        ->and(Media::query()->findOrFail($media->id)->uploaded_for)->toBeNull();
});

it('refuses an Uploaded for that names no item', function (): void {
    $media = mediaUpload($this->admin, mediaJpeg());

    expect(fn () => DB::table('media')->where('id', $media->id)->update(['uploaded_for' => strtolower((string) Str::ulid())]))
        ->toThrow(QueryException::class, 'media_uploaded_for_foreign');
});

it('can serve a source’s attachments by their WordPress parent from its own index', function (): void {
    $source = ImportRecords::source();
    $rows = [];

    foreach (range(1, 400) as $id) {
        foreach (['attachment', 'post'] as $type) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()), 'source_id' => $source, 'object_type' => $type, 'object_id' => (string) $id,
                'status' => 'skipped', 'outcome' => 'skipped', 'facts' => json_encode(['parent' => $id % 50]), 'imported_at' => now(),
            ];
        }
    }

    DB::table('import_map')->insert($rows);
    DB::statement('ANALYZE import_map');
    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = implode("\n", array_map(
        fn (object $line): string => (string) $line->{'QUERY PLAN'},
        DB::select("EXPLAIN SELECT teme_id FROM import_map WHERE source_id = ? AND object_type = 'attachment' AND facts->'parent' = '7'::jsonb", [$source]),
    ));

    expect($plan)->toContain('import_map_attachment_parent_index');
});
