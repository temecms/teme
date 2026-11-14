<?php

declare(strict_types=1);

use App\Domain\Media\Images\FocalCrop;

it('covers the square and centres the crop by default', function (): void {
    expect(FocalCrop::square(1200, 800, 300, 50, 50))->toBe([450, 300, 75, 0]);
});

it('moves the crop toward the focal point, without leaving the image', function (): void {
    expect(FocalCrop::square(1200, 800, 300, 0, 50))->toBe([450, 300, 0, 0])
        ->and(FocalCrop::square(1200, 800, 300, 100, 50))->toBe([450, 300, 150, 0])
        ->and(FocalCrop::square(800, 1200, 300, 50, 90))->toBe([300, 450, 0, 150]);
});

it('enlarges an image smaller than the square', function (): void {
    expect(FocalCrop::square(100, 50, 300, 50, 50))->toBe([600, 300, 150, 0]);
});
