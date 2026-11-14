<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->news = headTerm('category', 'News');
    headPost($this->author, 'Harbour news', [], [$this->news]);
    $this->folder = null;
    $this->robots = function (string $uri, int $status = 200): array {
        $dom = headDom($uri, $status);

        return [(int) $dom->evaluate('count(//head/meta[@name="robots"])'), headValue($dom, '//head/meta[@name="robots"]/@content')];
    };
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('writes the default directives on a page search engines may list: an item, the home page, an archive (I-291)', function (string $uri): void {
    expect(($this->robots)($uri))->toBe([1, 'max-snippet:-1, max-image-preview:large, max-video-preview:-1']);
})->with(['/harbour-news/', '/', '/category/news/']);

it('shapes an item\'s snippet with its own controls (I-714)', function (string $case, string $expected): void {
    headPost($this->author, 'Shaped', match ($case) {
        'no snippet' => [BuiltInFields::NOSNIPPET => true],
        'a snippet length' => [BuiltInFields::MAX_SNIPPET => 160],
        'no cached copy' => [BuiltInFields::NOARCHIVE => true],
        'all three' => [BuiltInFields::NOSNIPPET => true, BuiltInFields::MAX_SNIPPET => 160, BuiltInFields::NOARCHIVE => true],
        default => [BuiltInFields::MAX_SNIPPET => -5],
    });

    expect(($this->robots)('/shaped/'))->toBe([1, $expected]);
})->with([
    'no snippet' => ['no snippet', 'nosnippet, max-image-preview:large, max-video-preview:-1'],
    'a snippet length' => ['a snippet length', 'max-snippet:160, max-image-preview:large, max-video-preview:-1'],
    'no cached copy' => ['no cached copy', 'max-snippet:-1, max-image-preview:large, max-video-preview:-1, noarchive'],
    'all three' => ['all three', 'nosnippet, max-image-preview:large, max-video-preview:-1, noarchive'],
    'a length below nothing' => ['a length below nothing', 'max-snippet:-1, max-image-preview:large, max-video-preview:-1'],
]);

it('hides an item by its own box, dropping every default and snippet directive (I-291, I-693)', function (): void {
    headPost($this->author, 'Hidden one', [BuiltInFields::NOINDEX => true, BuiltInFields::NOSNIPPET => true, BuiltInFields::NOARCHIVE => true]);

    expect(($this->robots)('/hidden-one/'))->toBe([1, 'noindex, follow']);
});

it('hides every page of a type, and a taxonomy\'s and the dates\' archives, by their rows, and never the home page by any row (I-291, I-854)', function (string $row, string $uri): void {
    cmSettings([$row => true, 'hide_type_page' => true], SearchAndSocial::KEY);

    expect(($this->robots)($uri))->toBe([1, 'noindex, follow'])
        ->and(($this->robots)('/'))->toBe([1, 'max-snippet:-1, max-image-preview:large, max-video-preview:-1']);
})->with([
    'a type' => ['hide_type_post', '/harbour-news/'],
    'a taxonomy' => ['hide_taxonomy_category', '/category/news/'],
    'the dates' => ['hide_date', '/'.date('Y').'/'],
]);

it('keeps a search noindex, follow whatever the rows say, and gives a 404 no robots meta (I-291)', function (): void {
    cmSettings(['hide_type_post' => false], SearchAndSocial::KEY);

    expect(($this->robots)('/?s=harbour'))->toBe([1, 'noindex, follow'])
        ->and(($this->robots)('/no-such-page/', 404))->toBe([0, '']);
});

it('puts Discourage search engines first, over an item\'s own controls (I-291)', function (): void {
    headPost($this->author, 'Shaped', [BuiltInFields::NOSNIPPET => true, BuiltInFields::NOINDEX => true]);
    cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY);

    expect(($this->robots)('/shaped/'))->toBe([1, 'noindex, nofollow'])
        ->and(($this->robots)('/category/news/'))->toBe([1, 'noindex, nofollow']);
});

it('removes the stored pages of a type when its hide box changes, once the change commits (I-263)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $this->folder = cacheOn();
    addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheKept('/harbour-news/'))->toBe(['/harbour-news/']);

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('seo', ['hide_type_post' => true], [], [], 0, (string) $admin->id));

    expect(cacheKept('/harbour-news/'))->toBe([])
        ->and(($this->robots)('/harbour-news/'))->toBe([1, 'noindex, follow']);
});

it('draws the robots meta as the head\'s first entry, right after the title, ahead of this part\'s other tags (I-228)', function (): void {
    expect((string) addrGet('/harbour-news/')->assertOk()->getContent())
        ->toContain("</title>\n    <meta name=\"robots\" content=\"max-snippet:-1, max-image-preview:large, max-video-preview:-1\">\n");
});
