<?php

declare(strict_types=1);

use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UploadMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    $this->author = User::factory()->create()->assignRole('author');
});

it('keeps where a downloaded file came from (I-453)', function (): void {
    $media = app(UploadMedia::class)->execute(new UploadMediaInput(mediaJpeg(), 'harbour.jpg', (string) $this->author->id, false, 'https://photos.example/2026/harbour.jpg'));

    expect($media->source_url)->toBe('https://photos.example/2026/harbour.jpg')
        ->and(DB::table('media')->where('id', $media->id)->value('source_url'))->toBe('https://photos.example/2026/harbour.jpg');
});

it('keeps no source for a file someone uploaded', function (): void {
    expect(mediaUpload($this->author, mediaJpeg())->source_url)->toBeNull();
});

it('refuses a source that isn\'t a web address, or is longer than 2,048 characters', function (string $source): void {
    $media = mediaUpload($this->author, mediaJpeg());

    expect(fn () => DB::table('media')->where('id', $media->id)->update(['source_url' => $source]))
        ->toThrow(QueryException::class, 'media_source_url_check');
})->with([
    'another scheme' => ['ftp://photos.example/harbour.jpg'],
    'no host' => ['https:///harbour.jpg'],
    'a path alone' => ['/2026/harbour.jpg'],
    'too long' => ['https://photos.example/'.str_repeat('a', 2030)],
]);
