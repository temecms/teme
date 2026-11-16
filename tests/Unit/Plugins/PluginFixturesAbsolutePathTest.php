<?php

declare(strict_types=1);

use Tests\Fixtures\Plugins\PluginFixtures;

afterEach(fn () => PluginFixtures::forget());

it('keeps a Windows drive-letter path absolute, whether it uses backslashes or forward slashes', function (string $path): void {
    PluginFixtures::use($path);

    expect(config('teme.plugin_paths'))->toBe([$path]);
})->with([
    'backslash' => ['C:\\Users\\dev\\plugins\\hello'],
    'forward slash' => ['C:/Users/dev/plugins/hello'],
]);

it('keeps a UNC path absolute', function (): void {
    $path = '\\\\fileserver\\share\\plugins\\hello';

    PluginFixtures::use($path);

    expect(config('teme.plugin_paths'))->toBe([$path]);
});

it('still resolves a relative fixture folder under tests/Fixtures/plugins', function (): void {
    PluginFixtures::use('hello');

    expect(config('teme.plugin_paths'))->toBe([PluginFixtures::path('hello')]);
});
