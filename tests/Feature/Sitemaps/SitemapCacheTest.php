<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
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
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->editor, 'category', 'News');
    pageArticle($this->author, 'Harbour news', categories: [$this->news]);
    $this->folder = cacheOn();
});

afterEach(function (): void {
    cacheOff($this->folder);
});

it('stores the index, each page and the stylesheet under the tags that remove them, and serves them as they came (I-464)', function (): void {
    $miss = addrGet('/sitemap.xml')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/sitemap.xml')->assertHeader('X-Teme-Cache', 'HIT')->assertContent((string) $miss->getContent());
    addrGet('/sitemap-items-post.xml')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/sitemap-terms-category.xml')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/sitemap.xsl')->assertHeader('X-Teme-Cache', 'MISS');

    expect(smLocs((string) $miss->getContent()))->toContain('http://localhost/sitemap-items-post.xml')
        ->and(cacheEntry('/sitemap.xml')?->tags)->toContain('sitemap', 'seo', 'list:post')
        ->and(cacheEntry('/sitemap-items-post.xml')?->tags)->toContain('sitemap', 'seo', 'list:post')
        ->and(cacheEntry('/sitemap-terms-category.xml')?->tags)->toContain('sitemap', 'list:post', 'term:'.$this->news->id)
        ->and(cacheEntry('/sitemap.xsl')?->tags)->toContain('seo');
});

it('sets the index and a type\'s pages aside when an item of the type is published, and draws them afresh', function (): void {
    addrGet('/sitemap.xml')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/sitemap-items-post.xml')->assertHeader('X-Teme-Cache', 'MISS');

    pageArticle($this->author, 'Another story');

    expect(cacheSetAside('/sitemap.xml'))->not->toBeNull()
        ->and(cacheSetAside('/sitemap-items-post.xml'))->not->toBeNull()
        ->and(smLocs((string) addrGet('/sitemap-items-post.xml')->getContent()))->toContain('http://localhost/another-story/');
});

it('removes every stored sitemap when Search and social changes, once the change commits, and none when it rolls back (I-291)', function (): void {
    $sitemaps = ['/sitemap.xml', '/sitemap-items-post.xml', '/sitemap.xsl'];

    foreach ($sitemaps as $uri) {
        addrGet($uri)->assertHeader('X-Teme-Cache', 'MISS');
    }

    $change = fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('seo', [WholeSite::DISCOURAGE => true], [], [], 0, (string) $this->admin->id));

    cacheRolledBack($change);

    expect(cacheKept(...$sitemaps))->toBe($sitemaps);

    $change();

    expect(cacheKept(...$sitemaps))->toBe([]);

    addrGet('/sitemap.xml')->assertNotFound();
});
