<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionAction;
use Tests\Fixtures\Extensions\ExtensionsFolder;

beforeEach(function (): void {
    $this->folder = ExtensionsFolder::make();
});

afterEach(function (): void {
    $this->folder->delete();
});

it('installs a plugin against the pins, copying nothing the image has into the release', function (): void {
    $id = $this->folder->stage();
    $release = $this->folder->store->path($id);

    $result = $this->folder->composer()->change(ExtensionsFolder::request(ExtensionAction::Install, 'fixtures/hello-extension', '1.0.0'), $release);

    expect($result->ok())->toBeTrue($result->output);

    $lock = json_decode((string) file_get_contents($release.'/composer.lock'), true);
    $vendors = array_map('basename', glob($release.'/vendor/*', GLOB_ONLYDIR) ?: []);

    expect(array_column($lock['packages'], 'name'))->toBe(['fixtures/hello-extension'])
        ->and($vendors)->toBe(['composer', 'fixtures'])
        ->and($release.'/vendor/fixtures/hello-extension/teme-plugin.json')->toBeFile()
        ->and(is_link($release.'/vendor/fixtures/hello-extension'))->toBeFalse();
})->group('composer');

it('fails with Composer’s own reason when a package needs another version of one the image has', function (): void {
    $release = $this->folder->store->path($this->folder->stage());

    $result = $this->folder->composer()->change(ExtensionsFolder::request(ExtensionAction::Install, 'fixtures/wants-new-laravel', '1.0.0'), $release);

    expect($result->ok())->toBeFalse()
        ->and($result->lastLines())->toContain('laravel/framework')
        ->and($release.'/composer.lock')->not->toBeFile();
})->group('composer');

it('gives Composer none of the site’s secrets', function (): void {
    putenv('APP_KEY=base64:secret-of-the-site');
    putenv('DB_PASSWORD=secret-password');
    $_ENV['DB_PASSWORD'] = 'secret-password';

    try {
        $environment = $this->folder->composer()->environment(['github-oauth' => ['github.com' => 'a-key']]);
    } finally {
        putenv('APP_KEY');
        putenv('DB_PASSWORD');
        unset($_ENV['DB_PASSWORD']);
    }

    $passed = array_filter($environment, fn (string|false $value): bool => $value !== false);

    expect($environment['APP_KEY'])->toBeFalse()
        ->and($environment['DB_PASSWORD'])->toBeFalse()
        ->and(array_keys($passed))->toEqualCanonicalizing(['PATH', 'HOME', 'COMPOSER_HOME', 'COMPOSER_AUTH', 'COMPOSER_NO_INTERACTION', 'COMPOSER_MEMORY_LIMIT', ...(getenv('COMPOSER_ALLOW_SUPERUSER') === '1' ? ['COMPOSER_ALLOW_SUPERUSER'] : [])])
        ->and(json_decode((string) $passed['COMPOSER_AUTH'], true))->toBe(['github-oauth' => ['github.com' => 'a-key']])
        ->and($passed['COMPOSER_HOME'])->toBe($this->folder->root.'/.composer');
});

it('never runs a Composer plugin a package depends on', function (): void {
    $release = $this->folder->store->path($this->folder->stage());

    $result = $this->folder->composer()->change(ExtensionsFolder::request(ExtensionAction::Install, 'fixtures/composer-plugin-dependency', '1.0.0'), $release);

    expect($result->ok())->toBeTrue($result->output)
        ->and($release.'/vendor/fixtures/marker-plugin/composer.json')->toBeFile()
        ->and($release.'/composer-plugin-ran')->not->toBeFile()
        ->and(base_path('composer-plugin-ran'))->not->toBeFile();
})->group('composer');
