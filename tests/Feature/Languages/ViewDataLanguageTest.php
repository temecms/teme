<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\PageTags;
use App\Domain\Themes\ComposerRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\Languages\LanguageTheme;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    $this->theme = LanguageTheme::install();
});

afterEach(function (): void {
    LanguageTheme::remove($this->theme);
});

/**
 * The alternates a page was given, as tag => [address, current, isDefault].
 *
 * @return array<string, array{0: string, 1: bool, 2: bool}>
 */
function vdAlternates(string $address): array
{
    $data = LanguageTheme::read(addrGet($address)->assertOk());
    $root = rtrim(url('/'), '/');
    $alternates = [];

    foreach ($data['alternates'] as $alternate) {
        $alternates[$alternate['tag']] = [substr($alternate['url'], strlen($root)), $alternate['current'], $alternate['isDefault']];
    }

    return $alternates;
}

it('gives every theme view the page\'s language, its direction and its alternates', function (): void {
    $data = LanguageTheme::read(addrGet('/fr/bonjour/')->assertOk());

    expect($data['language'])->toMatchArray(['tag' => 'fr', 'name' => 'Français', 'isDefault' => false])
        ->and($data['language']['home'])->toEndWith('/fr/')
        ->and($data['direction'])->toBe('ltr')
        ->and(array_column($data['alternates'], 'tag'))->toBe(['en-GB', 'fr'])
        ->and(array_column($data['alternates'], 'current'))->toBe([false, true])
        ->and(array_column($data['alternates'], 'name'))->toBe(['English (United Kingdom)', 'Français'])
        ->and($data['site'])->toBe(['language' => 'en-GB', 'locale' => 'en_GB']);

    expect(vdAlternates('/hello/'))->toBe(['en-GB' => ['/hello/', true, true], 'fr' => ['/fr/bonjour/', false, false]]);
});

it('gives no alternates to a page with no other version', function (): void {
    expect(LanguageTheme::read(addrGet('/alone/')->assertOk())['alternates'])->toBe([]);
});

it('leaves out a version that is private, unpublished, dated ahead or in a hidden language', function (Closure $change): void {
    $change($this->site->postFr);

    expect(LanguageTheme::read(addrGet('/hello/')->assertOk())['alternates'])->toBe([]);
})->with([
    'private' => [fn (string $id) => DB::table('contents')->where('id', $id)->update(['visibility' => 'private'])],
    'unpublished' => [fn (string $id) => DB::table('contents')->where('id', $id)->update(['status' => 'draft'])],
    'dated ahead' => [fn (string $id) => DB::table('contents')->where('id', $id)->update(['published_at' => now()->addDay()])],
    'in a hidden language' => [fn () => Languages::set(['fr', 'es'], hidden: ['fr', 'es'])],
]);

it('gives the home page every shown language, and a type\'s archive the languages it has items in', function (): void {
    expect(vdAlternates('/'))->toBe(['en-GB' => ['/', true, true], 'fr' => ['/fr/', false, false]])
        ->and(vdAlternates('/fr/'))->toBe(['en-GB' => ['/', false, true], 'fr' => ['/fr/', true, false]]);

    ctCreate($this->site->admin);
    LanguageSite::version($this->site->editor, 'The film', null, null, [], publish: true, type: 'film');

    // Films has nothing published in French yet, so its archive has no other version.
    expect(LanguageTheme::read(addrGet('/films/')->assertOk())['alternates'])->toBe([]);

    LanguageSite::version($this->site->editor, 'Le film', 'fr', null, [], publish: true, type: 'film');

    expect(vdAlternates('/films/'))->toBe(['en-GB' => ['/films/', true, true], 'fr' => ['/fr/films/', false, false]]);
});

it('leaves a language with nothing published out of the home page\'s alternates', function (): void {
    DB::table('contents')->where('language', 'fr')->update(['status' => 'draft']);

    expect(LanguageTheme::read(addrGet('/')->assertOk())['alternates'])->toBe([]);
});

it('gives an author page, a search page, a later page of a listing and a 404 no alternates', function (): void {
    app(PathProjection::class)->syncUser($this->site->editor);
    cmSettings(['posts_per_page' => 1], 'reading');
    LanguageSite::version($this->site->editor, 'Howdy', null, null, [], publish: true);

    foreach (['/'.addrCurrent('user', (string) $this->site->editor->id).'/', '/?s=hello', '/page/2/'] as $address) {
        expect(LanguageTheme::read(addrGet($address)->assertOk())['alternates'])->toBe([], $address);
    }

    expect(LanguageTheme::read(addrGet('/nothing-here/')->assertNotFound())['alternates'])->toBe([]);
});

it('records the group\'s purge tag and each version\'s own', function (): void {
    $folder = cacheOn();

    try {
        addrGet('/fr/bonjour/')->assertOk();
        $recorded = cacheEntry('/fr/bonjour/')->tags ?? [];
    } finally {
        cacheOff($folder);
    }

    expect($recorded)->toContain(PageTags::translations($this->site->postGroup), PageTags::content($this->site->post), PageTags::content($this->site->postFr))
        ->and($recorded)->not->toContain(PageTags::content($this->site->postEs));
});

it('lets core give the reserved names', function (): void {
    expect(array_keys(LanguageTheme::read(addrGet('/fr/bonjour/')->assertOk())))->toContain('language', 'direction', 'alternates')
        ->and(ComposerRegistry::RESERVED)->toBe(['language', 'direction', 'alternates', 'plugins']);
});

it('tells a theme\'s composer the locale of the page being drawn', function (): void {
    expect(LanguageTheme::read(addrGet('/fr/bonjour/')->assertOk())['told'])->toBe('fr')
        ->and(LanguageTheme::read(addrGet('/hello/')->assertOk())['told'])->toBe('en-GB');
});
