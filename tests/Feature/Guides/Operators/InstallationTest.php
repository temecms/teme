<?php

declare(strict_types=1);

use App\Domain\Deployment\SecretFiles;
use App\Domain\Deployment\SecureTransport;
use App\Domain\Support\Security\TrustedProxies;
use Tests\Fixtures\Deployment\ImageFiles;
use Tests\Fixtures\Guides\OperatorGuide;

/*
 * The installation and configuration pages, held to the recipe, the image
 * and the application they describe.
 */

it('makes exactly the four secret files the recipe reads', function (): void {
    $files = array_map(fn (array $secret): string => basename((string) $secret['file']), array_values(OperatorGuide::recipe()['secrets']));
    preg_match_all('#secrets/([a-z_]+)#', OperatorGuide::page('install.md')->text(), $found);

    expect($files)->toEqualCanonicalizing(['app_key', 'db_password', 'postgres_password', 'github_token'])
        ->and(array_values(array_unique($found[1])))->toEqualCanonicalizing($files);
});

it('makes an application key as long as the cipher needs', function (): void {
    $blocks = implode("\n", array_column(OperatorGuide::page('install.md')->fences(), 'body'));

    expect(config('app.cipher'))->toBe('AES-256-CBC')
        ->and($blocks)->toContain('openssl rand -base64 32')
        ->and($blocks)->toContain('base64:');
});

it('takes the recipe from the image, which carries it', function (): void {
    $page = OperatorGuide::page('install.md')->text();

    expect(OperatorGuide::text('.dockerignore'))->toContain("\n!docker/\n")
        ->and(ImageFiles::stage('runtime'))->toContain('COPY --from=code /var/www/html /var/www/html')
        ->and($page)->toContain('docker create')
        ->and($page)->toContain('docker cp')
        ->and($page)->toContain('/var/www/html/docker');
});

it('pulls a private image from the registry by an exact version, never latest', function (): void {
    $page = OperatorGuide::page('install.md')->text();

    expect($page)->toContain('docker login ghcr.io')
        ->and($page)->not->toContain(':latest');
});

it('makes the first administrator with the command that invites one', function (): void {
    expect(array_column(OperatorGuide::commands(OperatorGuide::page('install.md')), 'name'))->toContain('teme:user:create');
});

it('names every variable the recipe sets or substitutes', function (): void {
    $named = [...OperatorGuide::variables(OperatorGuide::page('install.md')), ...OperatorGuide::variables(OperatorGuide::page('configure.md'))];

    expect(array_values(array_diff(OperatorGuide::recipeVariables(), $named)))->toBe([]);
});

it('names each secret that can come from a file, with the variable that points at the file', function (): void {
    $text = OperatorGuide::page('configure.md')->text();

    expect(array_values(array_filter(SecretFiles::NAMES, fn (string $name): bool => ! str_contains($text, $name.'_FILE'))))->toBe([]);
});

it('gives only TRUSTED_PROXIES values the application accepts, and at least one', function (): void {
    $values = [];

    foreach (OperatorGuide::PAGES as $name) {
        preg_match_all('/TRUSTED_PROXIES=([^\s`]+)/', OperatorGuide::page($name)->text(), $found);
        array_push($values, ...$found[1]);
    }

    foreach ($values as $value) {
        expect(fn () => TrustedProxies::parse($value))->not->toThrow(Throwable::class);
    }

    expect($values)->not->toBe([]);
});

it('lists the private ranges and gives the HSTS value the application sends by default', function (): void {
    $page = OperatorGuide::page('proxies-and-https.md')->text();

    foreach (TrustedProxies::PRIVATE as $range) {
        expect($page)->toContain($range);
    }

    expect($page)->toContain(SecureTransport::DEFAULT_HSTS);
});

it('names the settings that turn Cloudflare\'s purges on, and its ranges as the application knows them', function (): void {
    $page = OperatorGuide::page('cloudflare.md')->text();

    foreach (['PAGE_CACHE_CDN', 'CLOUDFLARE_ZONE_ID', 'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_PURGES_PER_MINUTE', 'Cache Purge', ...TrustedProxies::CLOUDFLARE] as $needle) {
        expect($page)->toContain($needle);
    }
});

it('states, beside each variable\'s name, the default the source gives it', function (): void {
    // From the source, not from config(): phpunit.xml sets these three for the suite.
    $defaults = [
        'ARGON_MEMORY' => OperatorGuide::text('config/hashing.php'),
        'ARGON_TIME' => OperatorGuide::text('config/hashing.php'),
        'AUTH_TIMEBOX_DURATION' => OperatorGuide::text('config/auth.php'),
    ];
    $page = OperatorGuide::page('sizing.md')->text();

    foreach ($defaults as $name => $source) {
        expect(preg_match("/env\\('{$name}', (\\d+)\\)/", $source, $default))->toBe(1);
        expect(preg_match('/'.$name.'[^\n]*\b'.$default[1].'\b/', $page))->toBe(1);
    }
});
