<?php

declare(strict_types=1);

beforeEach(fn () => config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/broken')]]));

it('links each usable theme\'s public folder into public/themes, and no broken one', function (): void {
    // A per-process public folder: the parallel gate shares the real public/ (I-441).
    $public = sys_get_temp_dir().'/teme-public-'.getmypid().'-'.uniqid();
    mkdir($public);
    $this->app->usePublicPath($public);

    $this->artisan('teme:themes:link')->assertSuccessful();

    expect(is_file($public.'/themes/good/build/manifest.json'))->toBeTrue()
        ->and(file_exists($public.'/themes/broken'))->toBeFalse();

    // Only the link goes: a recursive delete could follow it into the fixture.
    if (PHP_OS_FAMILY === 'Windows') {
        rmdir($public.'/themes/good');
    } else {
        unlink($public.'/themes/good');
    }
});
