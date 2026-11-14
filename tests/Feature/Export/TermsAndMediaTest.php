<?php

declare(strict_types=1);

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportFolder;
use App\Domain\Export\Kinds\MediaKind;
use App\Domain\Export\Kinds\TermsKind;
use App\Domain\Export\MediaTally;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Export\ExportAs;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->target = ExportAs::freshFolder();
    $this->folder = ExportFolder::open($this->target);
    $this->tally = new MediaTally;
    $this->media = fn (): array => iterator_to_array(app(MediaKind::class)->lines(new ExportContext($this->folder, $this->tally)), false);
    $this->line = fn (array $lines, string $id): array => collect($lines)->firstWhere('id', $id) ?? [];
});

afterEach(function (): void {
    $this->folder->discard();
});

it('carries every term with its parent by ID', function (): void {
    $harbour = taxonomyTerm('category', 'Harbour');
    $boats = taxonomyTerm('category', 'Boats', $harbour->id);
    $tides = taxonomyTerm('tag', 'Tides');

    $lines = ExportAs::kindLines(app(TermsKind::class), pageSize: 2);

    expect(array_column($lines, 'id'))->toEqualCanonicalizing([$harbour->id, $boats->id, $tides->id])
        ->and(($this->line)($lines, $boats->id))->toMatchArray(['taxonomy' => 'category', 'name' => 'Boats', 'slug' => 'boats', 'parent_id' => $harbour->id, 'description' => null])
        ->and(json_encode(($this->line)($lines, $tides->id)['fields']))->toBe('{}');
});

it('copies each original beside its record, as uploaded, and carries what people wrote about it', function (): void {
    $image = mediaUpload($this->admin, mediaJpeg(), 'Harbour.jpg');
    $pdf = mediaUpload($this->admin, mediaPdf(), 'Timetable.pdf');
    $image->forceFill(['alt' => 'Boats at dusk', 'credit' => 'Ada'])->save();

    $lines = ($this->media)();
    $line = ($this->line)($lines, $image->id);
    $copied = "{$this->folder->staging}/{$line['file']['path']}";

    expect($line)->toMatchArray([
        'media_kind' => 'image',
        'mime_type' => $image->mime_type,
        'original_filename' => 'Harbour.jpg',
        'alt' => 'Boats at dusk',
        'credit' => 'Ada',
        'width' => 1200,
        'height' => 800,
        'size_bytes' => $image->size_bytes,
        'sha256' => $image->sha256,
        'uploaded_by' => $this->admin->id,
    ])
        ->and($line['file']['path'])->toBe("media/{$image->id}.{$image->extension}")
        ->and($line['file']['sha256'])->toBe($image->sha256)
        ->and(hash_file('sha256', $copied))->toBe($image->sha256)
        ->and(($this->line)($lines, $pdf->id)['file']['sha256'])->toBe($pdf->sha256)
        ->and($this->tally->files)->toBe(2)
        ->and($this->tally->bytes)->toBe($image->size_bytes + $pdf->size_bytes)
        ->and(array_keys($line))->not->toContain('variants')
        ->and(array_keys($line))->not->toContain('original_path');
});

it('leaves media in the trash out, and copies none of its file', function (): void {
    $kept = mediaUpload($this->admin, mediaPdf(), 'Timetable.pdf');
    $gone = mediaUpload($this->admin, mediaPdf(), 'Old timetable.pdf', allowDuplicate: true);
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->admin->id));

    $ids = array_column(($this->media)(), 'id');

    expect($ids)->toBe([$kept->id])
        ->and(file_exists("{$this->folder->staging}/media/{$gone->id}.pdf"))->toBeFalse();
});

it('says which originals are missing and which have changed since upload, and carries on', function (): void {
    $missing = mediaUpload($this->admin, mediaPdf(), 'Missing.pdf');
    $changed = mediaUpload($this->admin, mediaPdf(), 'Changed.pdf', allowDuplicate: true);
    MediaDisks::forOriginal($missing->kind)->delete($missing->original_path);
    MediaDisks::forOriginal($changed->kind)->put($changed->original_path, 'not the upload');

    $lines = ($this->media)();

    expect(($this->line)($lines, $missing->id)['file'])->toBeNull()
        ->and(($this->line)($lines, $changed->id)['file']['sha256'])->toBe(hash('sha256', 'not the upload'))
        ->and(($this->line)($lines, $changed->id)['sha256'])->toBe($changed->sha256)
        ->and($this->tally->missing)->toBe([$missing->id])
        ->and($this->tally->differs)->toBe([$changed->id]);
});
