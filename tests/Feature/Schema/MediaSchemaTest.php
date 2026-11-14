<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

function mediaRawRow(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(), 'kind' => 'image', 'mime_type' => 'image/jpeg', 'extension' => 'jpg',
        'original_filename' => 'a.jpg', 'name' => 'a', 'original_path' => 'x/v1/a.jpg', 'file_version' => 1,
        'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'title' => 'A', 'variants' => '{}',
        'variant_set' => 0, 'processing' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

it('refuses an unknown kind, processing state or focal point', function (array $overrides): void {
    expect(fn () => DB::table('media')->insert(mediaRawRow($overrides)))->toThrow(QueryException::class);
})->with([
    'kind' => [['kind' => 'executable']],
    'processing' => [['processing' => 'maybe']],
    'focal x' => [['focal_x' => 101]],
    'variants not an object' => [['variants' => '[]']],
]);

it('gives authors their own media and editors everyone\'s', function (): void {
    $this->seed(RoleSeeder::class);

    expect(Role::findByName('author')->hasPermissionTo('media.upload'))->toBeTrue()
        ->and(Role::findByName('author')->hasPermissionTo('media.edit.any'))->toBeFalse()
        ->and(Role::findByName('editor')->hasPermissionTo('media.delete.any'))->toBeTrue()
        ->and(Role::findByName('contributor')->hasPermissionTo('media.upload'))->toBeFalse()
        ->and(Role::findByName('administrator')->hasPermissionTo('settings.media.manage'))->toBeTrue();
});

it('declares the media limits with their defaults', function (): void {
    $settings = app(SettingsReader::class);

    expect($settings->int('media', 'max_upload_mb'))->toBe(64)
        ->and($settings->int('media', 'max_megapixels'))->toBe(50);
});
