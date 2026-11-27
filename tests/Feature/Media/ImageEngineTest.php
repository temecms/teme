<?php

declare(strict_types=1);

use App\Domain\Media\Images\ImageEngine;
use Illuminate\Support\Str;

function mediaWorkDirectory(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-work-'.Str::random(8);
    mkdir($directory);

    return $directory;
}

it('makes WebP variants at each width and a square thumbnail', function (): void {
    $made = app(ImageEngine::class)->variants(mediaJpeg(1000, 500), [320, 640, 1000], 300, 50, 50, mediaWorkDirectory());

    expect(array_keys($made))->toBe(['w320', 'w640', 'w1000', 'thumb'])
        ->and($made['w640']['width'])->toBe(640)
        ->and($made['w640']['height'])->toBe(320)
        ->and($made['thumb']['width'])->toBe(300)
        ->and($made['thumb']['height'])->toBe(300)
        ->and(mime_content_type($made['w640']['file']))->toBe('image/webp')
        ->and($made['w640']['bytes'])->toBe(filesize($made['w640']['file']));
});

it('reads an image without decoding more than it must', function (): void {
    expect(app(ImageEngine::class)->inspect(mediaJpeg(640, 480)))->toBe(['width' => 640, 'height' => 480, 'animated' => false]);
});

it('reads an animated GIF of many frames from its first frame, and still knows it is animated (issue 806)', function (): void {
    $gif = mediaAnimatedGif(600, 600, 200);
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $read = app(ImageEngine::class)->inspect($gif);

    // Every frame decoded would need about 288 MB; the first frame alone a few.
    expect($read)->toBe(['width' => 600, 'height' => 600, 'animated' => true])
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(48 * 1024 * 1024);
});

it('makes the sizes of an animated GIF of many frames from its first frame (issue 806)', function (): void {
    $gif = mediaAnimatedGif(600, 600, 200);
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $made = app(ImageEngine::class)->variants($gif, [320], 150, 50, 50, mediaWorkDirectory());

    expect($made['w320']['width'])->toBe(320)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(48 * 1024 * 1024);
});

it('knows a still GIF is not animated', function (): void {
    expect(app(ImageEngine::class)->inspect(mediaAnimatedGif(40, 30, 1)))->toBe(['width' => 40, 'height' => 30, 'animated' => false]);
});

it('knows which formats the GD driver can read', function (): void {
    $engine = app(ImageEngine::class);

    expect($engine->canDecode('image/jpeg'))->toBeTrue()
        ->and($engine->canDecode('image/webp'))->toBeTrue()
        ->and($engine->canDecode('image/heic'))->toBeFalse()
        ->and($engine->canDecode('application/pdf'))->toBeFalse();
});

it('answers null for an image it can’t decode, rather than throwing', function (): void {
    expect(app(ImageEngine::class)->inspect(mediaDamagedGif()))->toBeNull();
});
