<?php

declare(strict_types=1);

use App\Domain\Extensions\CorePins;

/**
 * An installed.json as Composer writes it, with one dev package.
 *
 * @return array<string, mixed>
 */
function installedFixture(string $laravel = 'v13.4.0'): array
{
    return [
        'packages' => [
            ['name' => 'laravel/framework', 'version' => $laravel, 'replace' => ['illuminate/support' => 'self.version', 'illuminate/collections' => 'self.version'], 'provide' => ['psr/log-implementation' => '1.0|2.0|3.0']],
            ['name' => 'monolog/monolog', 'version' => '3.9.0', 'provide' => ['psr/log-implementation' => '3.0.0']],
            ['name' => 'teme/contracts', 'version' => 'dev-main'],
            ['name' => 'symfony/polyfill-php85', 'version' => 'v1.33.0', 'replace' => ['symfony/polyfill-php84' => '*']],
            ['name' => 'pestphp/pest', 'version' => 'v5.0.0'],
        ],
        'dev' => true,
        'dev-package-names' => ['pestphp/pest'],
    ];
}

it('pins every package, what each replaces and what each provides, self.version resolved', function (): void {
    $pins = (new CorePins)->build(installedFixture(), 'teme/teme');

    expect($pins['replace'])->toBe([
        'illuminate/collections' => 'v13.4.0',
        'illuminate/support' => 'v13.4.0',
        'laravel/framework' => 'v13.4.0',
        'monolog/monolog' => '3.9.0',
        'pestphp/pest' => 'v5.0.0',
        'symfony/polyfill-php84' => '*',
        'symfony/polyfill-php85' => 'v1.33.0',
        'teme/contracts' => 'dev-main',
        'teme/teme' => 'dev-main',
    ])
        ->and($pins['provide'])->toBe(['psr/log-implementation' => '1.0|2.0|3.0'])
        ->and($pins['hash'])->toMatch('/^[0-9a-f]{16}$/');
});

it('leaves out the dev packages when asked', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'installed');
    file_put_contents($file, json_encode(installedFixture()));

    $all = (new CorePins)->fromFile($file, 'teme/teme');
    $production = (new CorePins)->fromFile($file, 'teme/teme', withoutDev: true);
    unlink($file);

    expect($all['replace'])->toHaveKey('pestphp/pest')
        ->and($production['replace'])->not->toHaveKey('pestphp/pest')
        ->and($production['replace'])->toHaveKey('laravel/framework');
});

it('changes the hash when one version changes, and not when the order does', function (): void {
    $pins = new CorePins;
    $reordered = installedFixture();
    $reordered['packages'] = array_reverse($reordered['packages']);

    expect($pins->build(installedFixture(), 'teme/teme')['hash'])
        ->toBe($pins->build($reordered, 'teme/teme')['hash'])
        ->not->toBe($pins->build(installedFixture('v13.4.1'), 'teme/teme')['hash']);
});

it('writes the pins to a file the bootstrap reads back', function (): void {
    $path = sys_get_temp_dir().'/teme-core-pins-'.bin2hex(random_bytes(4)).'.php';

    $this->artisan('teme:extensions:pins', ['--no-dev' => true, '--path' => $path])->assertSuccessful();

    $pins = require $path;
    unlink($path);

    expect($pins['replace'])->toHaveKey('laravel/framework')
        ->and($pins['replace'])->toHaveKey('teme/teme')
        ->and($pins['replace'])->not->toHaveKey('pestphp/pest')
        ->and($pins['hash'])->toMatch('/^[0-9a-f]{16}$/');
});
