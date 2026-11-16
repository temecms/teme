<?php

declare(strict_types=1);

use App\Domain\Media\Models\MediaMove;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Backups\OtherSession;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media', ['url' => 'http://localhost/storage/media']);
    Storage::fake('media-originals');
    Storage::fake('media-bucket', ['url' => 'https://cdn.example.test']);
    Storage::fake('media-originals-bucket');
});

afterEach(function (): void {
    OtherSession::close();
});

/**
 * An image with one variant, its files on the server's faked disks. Its ID.
 */
function dsImage(): string
{
    $id = (string) Str::ulid();
    $bytes = "original bytes of {$id}";

    DB::table('media')->insert([
        'id' => $id, 'kind' => 'image', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'original_filename' => 'item.jpg',
        'name' => 'item', 'original_path' => "{$id}/v1/item.jpg", 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        'title' => 'Item', 'processing' => 'ready', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        'variants' => json_encode(['w640' => ['path' => "{$id}/v1/item-w640.webp", 'width' => 640, 'height' => 427, 'bytes' => 4]], JSON_THROW_ON_ERROR),
    ]);
    Storage::disk('media-originals')->put("{$id}/v1/item.jpg", $bytes);
    Storage::disk('media')->put("{$id}/v1/item-w640.webp", 'webp');

    return $id;
}

function dsRun(array $options): array
{
    $code = Artisan::call('teme:media:move', ['--from' => 'server', '--to' => 'bucket', ...$options]);

    return [$code, Artisan::output()];
}

/**
 * The site switched to the bucket, as the operator's MEDIA_STORAGE=bucket and deploy do.
 */
function dsSwitch(): void
{
    config(['media.disk' => 'media-bucket', 'media.originals_disk' => 'media-originals-bucket']);
}

/**
 * A full copy before the switch, the switch, and a catch-up after it: the order the guide gives.
 */
function dsMoved(): void
{
    dsRun([]);
    test()->travel(1)->seconds();
    dsSwitch();
    dsRun(['--catch-up' => true]);
}

function dsServerFiles(array $ids): array
{
    return collect($ids)->flatMap(fn (string $id): array => [
        Storage::disk('media-originals')->exists("{$id}/v1/item.jpg"),
        Storage::disk('media')->exists("{$id}/v1/item-w640.webp"),
    ])->all();
}

it('deletes every server copy after a verified move, and keeps every bucket copy', function (): void {
    $ids = [dsImage(), dsImage()];
    dsMoved();

    [$code, $output] = dsRun(['--delete-source' => true]);
    $run = MediaMove::query()->where('kind', 'delete_source')->sole();

    expect($code)->toBe(0)
        ->and(dsServerFiles($ids))->each->toBeFalse()
        ->and(Storage::disk('media-originals-bucket')->exists("{$ids[0]}/v1/item.jpg"))->toBeTrue()
        ->and(Storage::disk('media-bucket')->exists("{$ids[1]}/v1/item-w640.webp"))->toBeTrue()
        ->and($run->files_deleted)->toBe(4)
        ->and($run->state)->toBe('finished')
        ->and($output)->toContain('Deleted the copies in “server”');
});

it('keeps a server copy whose bucket copy went missing, counting it a failure', function (): void {
    $ids = [dsImage(), dsImage()];
    dsMoved();
    Storage::disk('media-originals-bucket')->delete("{$ids[1]}/v1/item.jpg");

    [$code] = dsRun(['--delete-source' => true]);
    $run = MediaMove::query()->where('kind', 'delete_source')->sole();

    expect($code)->toBe(1)
        ->and(Storage::disk('media-originals')->exists("{$ids[1]}/v1/item.jpg"))->toBeTrue()
        ->and(Storage::disk('media-originals')->exists("{$ids[0]}/v1/item.jpg"))->toBeFalse()
        ->and($run->failures)->toBe(1)
        ->and($run->files_deleted)->toBe(3);
});

it('refuses, deleting nothing, unless every rule holds', function (Closure $arrange, string $sentence): void {
    $ids = [dsImage()];
    $arrange();
    $runs = MediaMove::query()->count();

    [$code, $output] = dsRun(['--delete-source' => true]);

    expect($code)->toBe(2)
        ->and($output)->toContain($sentence)
        ->and(dsServerFiles($ids))->each->toBeTrue()
        ->and(MediaMove::query()->count())->toBe($runs);
})->with([
    'the site still on the server' => [fn () => dsRun([]), 'The site still uses “media-originals”'],
    'the latest copy failed' => [function (): void {
        dsMoved();
        DB::table('media_moves')->orderByDesc('started_at')->limit(1)->update(['failures' => 1]);
    }, 'The latest copy found 1 failures'],
    'the latest copy began before the switch' => [function (): void {
        dsRun([]);
        dsSwitch();
    }, 'began before the site switched'],
    'a gap in the chain' => [function (): void {
        dsMoved();
        // The catch-up's changes began after the full copy started: whatever changed between was missed.
        DB::table('media_moves')->where('scope', 'changed')->update(['changed_since' => now()->addHour()]);
    }, 'leave a gap'],
    'a backup running' => [function (): void {
        dsMoved();
        OtherSession::holdBackupLock();
    }, 'A backup is running'],
]);

it('says on a dry run whether the deletion may go ahead, deleting nothing', function (): void {
    $ids = [dsImage()];
    dsRun([]);
    dsSwitch();

    [$refused, $why] = dsRun(['--delete-source' => true, '--dry-run' => true]);

    test()->travel(1)->seconds();
    dsRun(['--catch-up' => true]);
    [$allowed, $yes] = dsRun(['--delete-source' => true, '--dry-run' => true]);

    expect($refused)->toBe(2)
        ->and($why)->toContain('began before the site switched')
        ->and($allowed)->toBe(0)
        ->and($yes)->toContain('may be deleted')
        ->and(dsServerFiles($ids))->each->toBeTrue()
        ->and(MediaMove::query()->where('kind', 'delete_source')->count())->toBe(0);
});

it('refuses --delete-source beside a copy’s options', function (): void {
    dsImage();

    [$code, $output] = dsRun(['--delete-source' => true, '--deep' => true]);

    expect($code)->toBe(2)
        ->and($output)->toContain('--delete-source runs alone');
});
