<?php

declare(strict_types=1);

use App\Domain\Backups\BackupRefused;
use App\Domain\Backups\BackupTables;
use App\Domain\Backups\DatabaseMoment;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\MediaInventory;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Media\MediaDisks;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Media\Storage\StorageEnvironment;
use Tests\Fixtures\Backups\CommittedMedia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    $this->media = new CommittedMedia;
    // A manifest as a backup makes one: a moment, the media it saw, the moment closed.
    $this->take = function (): array {
        $moment = DatabaseMoment::open();

        try {
            $inventory = MediaInventory::take($moment, MediaInventory::roots());

            return [$inventory, Manifest::make('01jbackuprun0000000000000a', CarbonImmutable::parse('2026-10-18 02:00:03.25', 'UTC'), $moment, $inventory)];
        } finally {
            $moment->close();
        }
    };
});

afterEach(function (): void {
    $this->media->delete();
});

it('lists every file the media rows name, by disk, and the ones missing from it, never listing a folder', function (): void {
    $image = $this->media->image(['w640', 'thumb']);
    $pdf = $this->media->document();
    $hole = $this->media->image(['w640'], withFiles: false);

    [$inventory] = ($this->take)();
    $media = $inventory->manifest();

    expect($inventory->fileCount())->toBe(6)
        ->and($inventory->missingCount())->toBe(2)
        ->and($inventory->firstMissing())->toBe("originals:{$hole}/1/original.jpg")
        ->and($media['items'])->toBe(3)
        ->and($media['files'])->toBe(6)
        ->and($media['variants'])->toBe(['files' => 3, 'bytes' => 27])
        ->and($media['roots'])->toBe([
            'originals' => rtrim(MediaDisks::originals()->path(''), '/\\'),
            'public' => rtrim(MediaDisks::public()->path(''), '/\\'),
        ])
        ->and($media['snapshot_roots']['public'])->toBe(BackupSnapshots::pathInSnapshot($media['roots']['public']))
        ->and(collect($media['originals'])->pluck('disk', 'id')->all())->toEqual([$image => 'originals', $pdf => 'public', $hole => 'originals'])
        ->and(collect($media['originals'])->firstWhere('id', $image))->toBe([
            'id' => $image,
            'disk' => 'originals',
            'path' => "{$image}/1/original.jpg",
            'bytes' => strlen("original of {$image}"),
            'sha256' => hash('sha256', "original of {$image}"),
        ])
        ->and($media['missing']['count'])->toBe(2)
        ->and($media['missing']['files'])->toEqualCanonicalizing([
            ['disk' => 'originals', 'path' => "{$hole}/1/original.jpg"],
            ['disk' => 'public', 'path' => "{$hole}/1/w640.webp"],
        ]);
});

it('refuses a media disk that isn’t a folder on this server (I-390)', function (): void {
    Storage::set((string) config('media.disk'), Mockery::mock(Filesystem::class));

    expect(fn () => MediaInventory::roots())
        ->toThrow(BackupRefused::class, 'The media disk “'.config('media.disk').'” isn’t a folder on this server, so Teme can’t back its files up.');
});

it('writes every key of format 1, and no secret', function (): void {
    // A key of the test's own, so "not in the manifest" can't pass on an empty one.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $image = $this->media->image();
    [, $manifest] = ($this->take)();
    $data = $manifest->toArray();

    expect(array_keys($data))->toBe(['format', 'run', 'made_at', 'teme', 'tagged', 'schema', 'database', 'media', 'theme', 'packages', 'app_key'])
        ->and($data['format'])->toBe(1)
        ->and($data['run'])->toBe('01jbackuprun0000000000000a')
        ->and($data['made_at'])->toBe('2026-10-18T02:00:03.250000+00:00')
        ->and($data['teme'])->toBe(config('teme.version'))
        ->and($data['tagged'])->toBe((bool) config('teme.tagged'))
        ->and(array_keys($data['database']))->toBe(['file', 'postgres', 'postgres_major', 'newest_migration', 'migrations', 'tables', 'structure_only'])
        ->and($data['database']['file'])->toBe('teme.dump')
        ->and($data['database']['postgres_major'])->toBe((int) $data['database']['postgres'])
        ->and($data['database']['structure_only'])->toBe(BackupTables::STRUCTURE_ONLY)
        ->and($data['database']['tables']['media'])->toBe(1)
        ->and(array_keys($data['media']))->toBe(['items', 'files', 'bytes', 'variants', 'locations', 'roots', 'snapshot_roots', 'originals', 'missing'])
        ->and(array_keys($data['media']['originals'][0]))->toBe(['id', 'disk', 'path', 'bytes', 'sha256'])
        ->and($data['media']['originals'][0]['id'])->toBe($image)
        ->and($data['app_key'])->toBe('app-key.json')
        ->and($manifest->tables())->toBe($data['database']['tables'])
        ->and(Manifest::fromJson($manifest->toJson())->toArray())->toBe(json_decode($manifest->toJson(), true))
        ->and($manifest->toJson())->not->toContain((string) config('app.key'))
        ->and($manifest->toJson())->not->toContain(substr((string) config('app.key'), 7));
});

it('names the theme the moment’s settings choose and its version, and every package Teme runs with but development ones', function (): void {
    // Chosen in this test's own transaction, which the moment never sees: the
    // committed settings name no theme, so the setting's default stands.
    themeActivate('no-such-theme');
    [, $manifest] = ($this->take)();
    $data = $manifest->toArray();

    expect($data['theme'])->toBe(['code' => 'default', 'version' => '0.1.0'])
        ->and($data['packages'])->toHaveKey('laravel/framework')
        ->and($data['packages']['laravel/framework'])->toStartWith('v13.')
        ->and($data['packages'])->not->toHaveKey('pestphp/pest')
        ->and(array_keys($data['packages']))->toBe(collect(array_keys($data['packages']))->sort()->values()->all());
});

it('reads back only a manifest of its own format', function (): void {
    expect(fn () => Manifest::fromJson('{"format": 2}'))
        ->toThrow(InvalidArgumentException::class, 'That isn’t a manifest of a format this Teme reads.')
        ->and(fn () => Manifest::fromJson('[1]'))
        ->toThrow(InvalidArgumentException::class, 'That isn’t a manifest of a format this Teme reads.');
});

it('leaves media in a bucket to the bucket: listed with checksums, counted, never checked or backed up (I-3456)', function (): void {
    $image = $this->media->image(['w640', 'thumb']);
    $pdf = $this->media->document();

    StorageEnvironment::with(StorageEnvironment::bucket(), function () use ($image, $pdf): void {
        [$inventory] = ($this->take)();
        $media = $inventory->manifest();

        expect($media['locations'])->toBe(['public' => 'bucket', 'originals' => 'bucket'])
            ->and($media['roots'])->toBe([])
            ->and($media['snapshot_roots'])->toBe([])
            ->and($inventory->folders())->toBe([])
            ->and($inventory->bucketFileCount())->toBe(4)
            ->and($inventory->missingCount())->toBe(0)
            ->and(collect($media['originals'])->pluck('disk', 'id')->all())->toEqual([$image => 'originals', $pdf => 'public'])
            ->and(collect($media['originals'])->firstWhere('id', $image)['sha256'])->toBe(hash('sha256', "original of {$image}"));
    });
});

it('backs up only the server’s role when the originals stay on the server and the rest is in the bucket', function (): void {
    StorageEnvironment::with(StorageEnvironment::bucket(['MEDIA_ORIGINALS_DISK' => 'media-originals']), function (): void {
        Storage::fake('media-originals');
        // No files: the original is checked on the server and found missing; the variant, in the bucket, isn't checked.
        $this->media->image(['w640'], withFiles: false);
        [$inventory] = ($this->take)();
        $media = $inventory->manifest();

        expect($media['locations'])->toBe(['public' => 'bucket', 'originals' => 'server'])
            ->and(array_keys($media['roots']))->toBe(['originals'])
            ->and(array_keys($media['snapshot_roots']))->toBe(['originals'])
            ->and($inventory->folders())->toBe([rtrim(Storage::disk('media-originals')->path(''), '/\\')])
            ->and($inventory->bucketFileCount())->toBe(1)
            ->and($inventory->missingCount())->toBe(1);
    });
});
