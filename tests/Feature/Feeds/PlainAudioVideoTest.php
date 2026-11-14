<?php

declare(strict_types=1);

use App\Domain\Feeds\FeedBody;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->body = fn (array ...$nodes): string => app(FeedBody::class)->html(['type' => 'doc', 'content' => $nodes]);
    $this->url = fn (Media $file): string => url(MediaDisks::public()->url($file->original_path));
});

it('writes an audio card as the browser\'s player at an absolute address, with a link to the file inside and its caption (I-861)', function (): void {
    $audio = avUpload($this->author, avAudioFile(), 'Tide report.mp3');
    $url = e(($this->url)($audio));

    expect(($this->body)(avAudioNode($audio, ['caption' => 'The tide report', 'transcript' => 'Good morning from the quay.'])))
        ->toBe('<figure><audio src="'.$url.'" controls preload="none"><a href="'.$url.'">Tide report</a></audio><figcaption>The tide report</figcaption></figure>');
});

it('writes a video card with its poster and size, and with controls even in loop mode, never playing by itself (I-861)', function (): void {
    $video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');
    $poster = pageImage($this->author, 1600, 900);
    DB::table('media')->where('id', $poster->id)->update(['variants' => json_encode([
        'w640' => ['path' => "{$poster->id}/v1/harbour-w640.webp", 'width' => 640, 'height' => 360, 'bytes' => 1000],
        'w1280' => ['path' => "{$poster->id}/v1/harbour-w1280.webp", 'width' => 1280, 'height' => 720, 'bytes' => 1000],
    ])]);
    $url = e(($this->url)($video));

    expect(($this->body)(avVideoNode($video, ['posterMediaId' => $poster->id, 'videoWidth' => 1920, 'videoHeight' => 1080, 'loop' => true, 'caption' => 'Dawn'])))
        ->toBe('<figure><video src="'.$url.'" controls preload="none" poster="'.e(url(MediaDisks::public()->url("{$poster->id}/v1/harbour-w1280.webp"))).'" width="1920" height="1080"><a href="'.$url.'">Harbour dawn</a></video><figcaption>Dawn</figcaption></figure>');
});

it('leaves out caption tracks and the transcript, which a feed reader can\'t use (I-861)', function (): void {
    $video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');
    $captions = avUpload($this->author, avCaptionsFile(), 'Harbour dawn.vtt');
    $audio = avUpload($this->author, avAudioFile(), 'Tide report.mp3');

    $html = ($this->body)(
        avVideoNode($video, ['tracks' => [['mediaId' => $captions->id, 'language' => 'en', 'kind' => 'captions', 'label' => 'English', 'default' => true]]]),
        avAudioNode($audio, ['transcript' => 'Good morning from the quay.']),
    );

    expect($html)->not->toContain('<track')->not->toContain('Good morning')->not->toContain('<details');
});

it('writes nothing for a file that has gone, or one of the wrong kind (I-861)', function (): void {
    $audio = avUpload($this->author, avAudioFile(), 'Tide report.mp3');

    expect(($this->body)(avAudioNode($audio, ['mediaId' => '01K6A0D1000000000000000ZZ9'])))->toBe('')
        ->and(($this->body)(avVideoNode($audio)))->toBe('');
});
