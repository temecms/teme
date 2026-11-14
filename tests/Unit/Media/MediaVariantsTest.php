<?php

declare(strict_types=1);

use App\Domain\Media\MediaVariants;

it('never enlarges, and always includes the original width up to 2560', function (int $width, array $expected): void {
    expect(MediaVariants::widthsFor($width))->toBe($expected);
})->with([
    'tiny' => [200, [200]],
    'between steps' => [1000, [320, 640, 960, 1000]],
    'exactly a step' => [1280, [320, 640, 960, 1280]],
    'larger than the largest' => [4000, [320, 640, 960, 1280, 1920, 2560]],
]);
