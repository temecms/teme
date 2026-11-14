<?php

declare(strict_types=1);

use App\Domain\Plugins\HostPattern;
use App\Domain\Themes\Manifest;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/csp-origins'), base_path('tests/Fixtures/themes/plain')], 'app.url' => 'http://localhost']);
    themeActivate('csp-origins');
    $this->author = User::factory()->create()->assignRole('author');
    addrPublished($this->author, 'Harbour news');
});

it('gives a page the origins its theme declares, by kind', function (): void {
    $policy = PolicyHeaders::of(addrGet('/harbour-news/')->assertOk());

    expect(PolicyHeaders::sources($policy, 'font-src'))->toContain('https://fonts.example.com')
        ->and(PolicyHeaders::sources($policy, 'img-src'))->toContain('https://*.images.example.net');
});

it("gives no directive an origin the theme didn't declare", function (): void {
    $policy = PolicyHeaders::of(addrGet('/harbour-news/')->assertOk());

    expect($policy)->toContain('https://fonts.example.com');

    foreach (['script-src', 'connect-src', 'frame-src', 'style-src'] as $directive) {
        expect(PolicyHeaders::sources($policy, $directive))->not->toContain('https://fonts.example.com');
    }

    expect($policy)->not->toContain('https://cdn.example.net');

    themeActivate('plain');

    expect(PolicyHeaders::of(addrGet('/harbour-news/')->assertOk()))->not->toContain('example.com')->not->toContain('example.net');
});

it("refuses a csp that isn't an object of known kinds, a host with a scheme, path, port or keyword, and more than 20, in these words", function (mixed $csp, array $expected): void {
    $errors = app(ManifestValidator::class)->errors(pageManifest(['csp' => $csp]), base_path('tests/Fixtures/themes/plain'));

    expect($errors)->toBe($expected);
})->with([
    'a list' => [['fonts.example.com'], ['csp must be an object whose keys are among script, style, font, img, connect and frame, each a list of host names.']],
    'a string' => ['fonts.example.com', ['csp must be an object whose keys are among script, style, font, img, connect and frame, each a list of host names.']],
    'an unknown kind' => [['media' => ['a.example.com']], ['csp must be an object whose keys are among script, style, font, img, connect and frame, each a list of host names.']],
    'a kind not a list' => [['font' => 'fonts.example.com'], ['csp must be an object whose keys are among script, style, font, img, connect and frame, each a list of host names.']],
    'a scheme' => [['font' => ['https://fonts.example.com']], ['csp.font holds “https://fonts.example.com”, which isn\'t a host name. Write it as cdn.example.com or *.example.com, with no https://, path, port or quoted keyword: https is implied.']],
    'a path' => [['script' => ['cdn.example.com/js']], ['csp.script holds “cdn.example.com/js”, which isn\'t a host name. Write it as cdn.example.com or *.example.com, with no https://, path, port or quoted keyword: https is implied.']],
    'a port' => [['connect' => ['api.example.com:8443']], ['csp.connect holds “api.example.com:8443”, which isn\'t a host name. Write it as cdn.example.com or *.example.com, with no https://, path, port or quoted keyword: https is implied.']],
    'a keyword' => [['script' => ["'unsafe-inline'"]], ['csp.script holds “\'unsafe-inline\'”, which isn\'t a host name. Write it as cdn.example.com or *.example.com, with no https://, path, port or quoted keyword: https is implied.']],
    'not a string' => [['img' => [42]], ['csp.img holds “42”, which isn\'t a host name. Write it as cdn.example.com or *.example.com, with no https://, path, port or quoted keyword: https is implied.']],
    '21 hosts' => [['frame' => array_map(fn (int $n): string => "f{$n}.example.com", range(1, 21))], ['csp.frame lists more than 20 hosts.']],
]);

it('accepts a theme without csp, and reads it as no origins', function (): void {
    expect(app(ManifestValidator::class)->errors(pageManifest([]), base_path('tests/Fixtures/themes/plain')))->toBe([])
        ->and(app(ManifestValidator::class)->errors(pageManifest(['csp' => ['font' => ['fonts.example.com'], 'img' => ['*.images.example.net'], 'style' => []]]), base_path('tests/Fixtures/themes/plain')))->toBe([])
        ->and((new Manifest(pageManifest([])))->csp())->toBe([])
        ->and((new Manifest(pageManifest(['csp' => ['font' => ['Fonts.example.com'], 'style' => []]])))->csp())->toBe(['font' => ['https://fonts.example.com'], 'style' => []]);
});

it("keeps the schema's members and the validator's keys the same", function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-theme.schema.json')), true);
    $described = array_keys($schema['properties']);
    $expected = [...ManifestValidator::KEYS, '$schema', 'author', 'license'];
    sort($described);
    sort($expected);
    $csp = $schema['properties']['csp'];
    $pattern = trim(substr(HostPattern::PATTERN, 1, strrpos(HostPattern::PATTERN, '/') - 1));

    expect($described)->toBe($expected)
        ->and(ManifestValidator::KEYS)->toContain('csp')
        ->and($csp['type'])->toBe('object')
        ->and($csp['additionalProperties'])->toBeFalse()
        ->and(array_keys($csp['properties']))->toBe(['style', 'font', 'img', 'connect', 'frame', 'script']);

    foreach ($csp['properties'] as $kind) {
        expect($kind)->toBe(['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'pattern' => $pattern]]);
    }
});

it("adds the theme's dev server only while it runs hot, locally", function (): void {
    $hot = base_path('tests/Fixtures/themes/csp-origins/public/hot');
    file_put_contents($hot, 'http://127.0.0.1:5199');

    try {
        app()->detectEnvironment(fn (): string => 'local');
        $local = PolicyHeaders::of(addrGet('/harbour-news/')->assertOk());

        app()->detectEnvironment(fn (): string => 'production');
        $production = PolicyHeaders::of(addrGet('/harbour-news/')->assertOk());
    } finally {
        unlink($hot);
    }

    expect(PolicyHeaders::sources($local, 'script-src'))->toContain('http://127.0.0.1:5199')
        ->and(PolicyHeaders::sources($local, 'style-src'))->toContain('http://127.0.0.1:5199')
        ->and(PolicyHeaders::sources($local, 'connect-src'))->toContain('http://127.0.0.1:5199', 'ws://127.0.0.1:5199')
        ->and($production)->not->toContain('127.0.0.1');
});
