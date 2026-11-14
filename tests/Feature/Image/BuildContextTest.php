<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Fixtures\Deployment\DockerIgnore;
use Tests\Fixtures\Deployment\ImageFiles;

/*
 * A build context is every file in the folder an image is built from. The
 * working folder holds private files (local notes, say), so .dockerignore
 * leaves everything out, lets back in only what the image is built from, and
 * names each private file besides.
 */

it('requires PHP\'s intl extension, in composer.json and its lock file (I-3116)', function (): void {
    $composer = json_decode(ImageFiles::text('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode(ImageFiles::text('composer.lock'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['ext-intl'] ?? null)->toBe('*')
        ->and($lock['platform']['ext-intl'] ?? null)->toBe('*');
});

it('leaves everything out first, so a file nobody named never reaches a build', function (): void {
    $first = DockerIgnore::rules()[0];

    expect($first['pattern'])->toBe('*')
        ->and($first['include'])->toBeFalse()
        ->and(DockerIgnore::excludes('a-file-nobody-named.txt'))->toBeTrue()
        ->and(DockerIgnore::excludes('notes/private.md'))->toBeTrue();
});

it('keeps every private file out of the build', function (string $path): void {
    expect(DockerIgnore::excludes($path))->toBeTrue();
})->with([
    'local notes' => 'CMSes.txt',
    'a chat' => 'GPT chat.txt',
    'a site\'s plugin list' => 'Plugins on WPsites.txt',
    'the environment' => '.env',
    'a copy of it' => '.env.backup',
    'production\'s' => '.env.production',
    'the recipe\'s' => 'docker/.env',
    'a theme\'s' => 'themes/default/.env',
    'the recipe\'s secrets' => 'docker/secrets/app_key',
    'the reference systems' => 'Reference/Ghost-main/package.json',
    'the builder\'s scratch' => '.laravel-tmp/report.txt',
    'Claude\'s settings' => '.claude/settings.json',
    'Claude\'s local settings' => '.claude/settings.local.json',
    'Claude\'s launcher' => '.claude/launch.json',
    'git' => '.git/config',
    'a package\'s own git' => 'packages/teme-contracts/.git/config',
    'Composer\'s credentials' => 'auth.json',
    'a theme\'s Composer credentials' => 'themes/default/auth.json',
]);

it('names each private file itself, after every line that lets files back in', function (string $pattern): void {
    $rules = DockerIgnore::rules();
    $lastInclude = max(array_keys(array_filter($rules, fn (array $rule): bool => $rule['include'])));
    $named = array_keys(array_filter($rules, fn (array $rule): bool => $rule['pattern'] === $pattern && ! $rule['include']));

    expect($named)->not->toBeEmpty()
        ->and(min($named))->toBeGreaterThan($lastInclude);
})->with(['CMSes.txt', 'GPT chat.txt', 'Plugins on WPsites.txt', 'Reference', '.laravel-tmp', '.claude', '.env', '**/.env']);

it('keeps out every private file .gitignore names', function (): void {
    $teme = Str::between(ImageFiles::text('.gitignore'), '# --- Teme', '# --- Starter kit');
    preg_match_all('#^/([^\n*]+?)/?$#m', $teme, $found);

    expect($found[1])->toContain('CMSes.txt', 'GPT chat.txt', 'Plugins on WPsites.txt', 'Reference', '.laravel-tmp', 'docker/secrets');

    foreach ($found[1] as $path) {
        expect(DockerIgnore::excludes($path))->toBeTrue()
            ->and(DockerIgnore::excludes($path.'/anything'))->toBeTrue();
    }
});

it('lets in what the image is built from', function (string $path): void {
    expect(DockerIgnore::excludes($path))->toBeFalse();
})->with([
    'artisan',
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    '.npmrc',
    'tsconfig.json',
    'vite.config.ts',
    'vite.theme.config.ts',
    'app/Providers/AppServiceProvider.php',
    'bootstrap/app.php',
    'bootstrap/providers.php',
    'config/teme.php',
    'database/migrations/2026_09_18_065922_create_contents_table.php',
    'docker/teme/nginx-hsts.php',
    'docker/nginx/conf.d/teme-static.conf',
    'packages/teme-contracts/composer.json',
    'public/index.php',
    'resources/js/app.tsx',
    'routes/web.php',
    'themes/default/teme-theme.json',
]);

it('keeps out what a working folder builds, links or keeps for tests and documents', function (string $path): void {
    expect(DockerIgnore::excludes($path))->toBeTrue();
})->with([
    'node_modules/vite/package.json',
    'themes/default/node_modules/x/index.js',
    'vendor/autoload.php',
    'packages/teme-contracts/vendor/autoload.php',
    'public/build/manifest.json',
    'public/hot',
    'public/storage',
    'public/themes/default',
    'resources/js/routes/index.ts',
    'resources/js/wayfinder/index.ts',
    'bootstrap/cache/config.php',
    'storage/logs/laravel.log',
    'database/database.sqlite',
    'tests/Pest.php',
    'docs/roadmap.md',
    'scripts/cloud-setup.sh',
    'phpunit.xml',
]);

it('keeps the recipe\'s secrets folder out of git', function (): void {
    expect(ImageFiles::text('.gitignore'))->toContain("\n/docker/secrets/\n");
});
