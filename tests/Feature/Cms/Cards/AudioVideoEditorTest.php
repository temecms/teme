<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Media\MediaDisks;
use App\Models\User;
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

it('gives the editor both cards after core\'s six, with their attributes and defaults (I-446)', function (): void {
    $cards = collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->keyBy('name');
    $defaults = fn (string $card): array => collect($cards[$card]['attributes'])->mapWithKeys(fn (array $attribute): array => [$attribute['name'] => $attribute['default']])->all();

    expect($cards->keys()->slice(6, 2)->values()->all())->toBe(['audio', 'video'])
        ->and($defaults('audio'))->toBe(['id' => null, 'mediaId' => null, 'caption' => null, 'transcript' => null])
        ->and($defaults('video'))->toBe(['id' => null, 'mediaId' => null, 'posterMediaId' => null, 'caption' => null, 'videoWidth' => null, 'videoHeight' => null, 'loop' => false, 'tracks' => null])
        ->and($cards['audio']['keywords'])->toContain('podcast')
        ->and($cards['video']['menuLabel'])->toBe('Video');
});

it('keeps the size the browser read from a video, and none it couldn\'t read, never 0 (I-446, I-452)', function (): void {
    $documents = guardEditor()['documents'];

    expect($documents['video as its view leaves it']['content'][0]['attrs'])->toMatchArray(['videoWidth' => 1920, 'videoHeight' => 1080, 'loop' => true])
        ->and($documents['video whose size the browser couldn’t read']['content'][0]['attrs'])->toMatchArray(['videoWidth' => null, 'videoHeight' => null]);
});

it('adds a track as captions in the language given, labelled with that language\'s own name, on by default only when first (I-446)', function (): void {
    $tracks = guardEditor()['documents']['video as its view leaves it']['content'][0]['attrs']['tracks'];

    expect($tracks[0])->toBe(['mediaId' => '01K6A0D1000000000000000VT1', 'language' => 'en', 'kind' => 'captions', 'label' => 'English', 'default' => true])
        ->and($tracks[1])->toMatchArray(['language' => 'pt-BR', 'kind' => 'subtitles', 'default' => false]);
});

it('accepts in full what the views write, each track checked (I-446, I-452)', function (string $name): void {
    expect(fn () => docValidate(guardEditor()['documents'][$name]))->not->toThrow(Throwable::class);
})->with(['video as its view leaves it', 'video whose size the browser couldn’t read', 'audio as its view leaves it']);

it('opens the editor with every file a video card shows, its poster and tracks included (I-446)', function (): void {
    $video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');
    $captions = avUpload($this->author, avCaptionsFile(), 'Harbour dawn.vtt');
    $poster = pageImage($this->author, 1600, 900);
    $item = lifecycleDraft($this->author, 'Harbour news');
    docSave($item, $this->author, docWith(avVideoNode($video, [
        'posterMediaId' => $poster->id,
        'tracks' => [['mediaId' => $captions->id, 'language' => 'en', 'kind' => 'captions', 'label' => 'English', 'default' => true]],
    ])));

    $session = EditorSession::for($item->id, (string) $this->author->id);

    expect(array_keys($session->media))->toEqualCanonicalizing([$video->id, $poster->id, $captions->id])
        ->and($session->media[$video->id]['fileUrl'])->toBe(MediaDisks::public()->url($video->original_path))
        ->and($session->media[$captions->id]['mimeType'])->toBe('text/vtt');
});
