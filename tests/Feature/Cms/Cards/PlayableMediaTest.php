<?php

declare(strict_types=1);

use App\Domain\Cards\CardMedia;
use App\Domain\Media\MediaDisks;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

it('gives each file its kind, its type, when it was uploaded and, for sound, video and documents, its own public address (I-446)', function (): void {
    $video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');
    $audio = avUpload($this->author, avAudioFile(), 'Tide report.mp3');
    $captions = avUpload($this->author, avCaptionsFile(), 'Harbour dawn.vtt');

    $media = app(CardMedia::class)->forIds([$video->id, $audio->id, $captions->id]);

    expect($media[$video->id])->toMatchArray(['kind' => 'video', 'mimeType' => 'video/mp4', 'fileUrl' => MediaDisks::public()->url($video->original_path), 'title' => 'Harbour dawn'])
        ->and($media[$audio->id])->toMatchArray(['kind' => 'audio', 'mimeType' => 'audio/mpeg', 'fileUrl' => MediaDisks::public()->url($audio->original_path)])
        ->and($media[$captions->id])->toMatchArray(['kind' => 'document', 'mimeType' => 'text/vtt', 'fileUrl' => MediaDisks::public()->url($captions->original_path)])
        ->and($media[$video->id]['uploadedAt'])->toBe(CarbonImmutable::parse((string) $video->created_at)->utc()->format(DATE_ATOM));
});

it('never gives an image\'s file address, since its original is private (I-446)', function (): void {
    $image = pageImage($this->author, 800, 600);

    expect(app(CardMedia::class)->forIds([$image->id])[$image->id])
        ->toMatchArray(['kind' => 'image', 'fileUrl' => null, 'mimeType' => 'image/jpeg']);
});

it('hands the editor a video\'s kind and address through content.card-media (I-446)', function (): void {
    $video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');

    $this->actingAs($this->author)
        ->getJson('/admin/content/card-media?'.http_build_query(['ids' => [$video->id]]))
        ->assertOk()
        ->assertJsonPath("{$video->id}.kind", 'video')
        ->assertJsonPath("{$video->id}.fileUrl", MediaDisks::public()->url($video->original_path));
});

it('still reads every file a document shows in one statement (I-446)', function (): void {
    $video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');
    $captions = avUpload($this->author, avCaptionsFile(), 'Harbour dawn.vtt');
    $poster = pageImage($this->author, 1600, 900);

    expect(dashQueries(fn () => app(CardMedia::class)->forIds([$video->id, $captions->id, $poster->id])))->toHaveCount(1);
});
