<?php

declare(strict_types=1);

use App\Domain\Media\MediaDisks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

it('takes a WebVTT caption file into the library as a document, stored as .vtt on the public disk (I-446)', function (): void {
    $captions = avUpload($this->author, avCaptionsFile(), 'Harbour captions.vtt');

    expect($captions->kind)->toBe('document')
        ->and($captions->mime_type)->toBe('text/vtt')
        ->and($captions->extension)->toBe('vtt')
        ->and($captions->original_path)->toEndWith('/harbour-captions.vtt')
        ->and(MediaDisks::public()->exists($captions->original_path))->toBeTrue();
});

it('knows a caption file by its content: WebVTT under another name is stored as .vtt, and plain text named .vtt stays text (I-446)', function (): void {
    $renamed = avUpload($this->author, avCaptionsFile(), 'captions.txt');
    $plain = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.vtt';
    file_put_contents($plain, "Just some notes.\n");
    $notes = avUpload($this->author, $plain, 'notes.vtt');

    expect([$renamed->mime_type, $renamed->extension])->toBe(['text/vtt', 'vtt'])
        ->and([$notes->mime_type, $notes->extension])->toBe(['text/plain', 'txt']);
});

it('still refuses a SubRip file, which a browser\'s player can\'t read (I-446)', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.srt';
    file_put_contents($path, "1\n00:00:00,000 --> 00:00:02,000\nThe tide comes in.\n");

    expect(fn () => avUpload($this->author, $path, 'captions.srt'))
        ->toThrow(ValidationException::class, 'Files of this type cannot be uploaded.');
});
