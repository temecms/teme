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
