<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps an export file on the private local disk, under the configured size limit', function (): void {
    expect(config('teme.import.max_file_mb'))->toBe(2048);

    $file = ImportFile::query()->create([
        'name' => 'export.xml',
        'size_bytes' => 1024,
        'received_bytes' => 0,
        'state' => 'uploading',
    ]);

    $absolute = Storage::disk('local')->path($file->path());
    $privateRoot = realpath(storage_path('app/private')) ?: storage_path('app/private');

    expect($file->path())->toBe('imports/'.$file->id.'.xml')
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'))
        ->and(str_starts_with($absolute, $privateRoot.DIRECTORY_SEPARATOR))->toBeTrue()
        ->and(str_contains($file->path(), 'public'))->toBeFalse();
});
