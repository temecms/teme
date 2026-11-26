<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

/*
 * A region declared with minWidth: a placeholder in the page, nothing of the
 * region in it, and its content answered at /_teme/region for core's script.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => [base_path('tests/Fixtures/themes/wide')]]);
    themeActivate('wide');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->ad = '<script src="https://ads.example/a.js"></script><script>window.halfPage=1</script><div>Half page ad</div>';
    $this->folder = null;
    $this->place = function (string $code, string $region = 'half-page'): void {
        $content = regionCreate($this->admin, 'wide', $region);
        scElevated(function () use ($content, $code): void {
            regionSave($content, $this->admin, [['type' => 'site-code', 'attrs' => ['id' => (string) Str::ulid(), 'code' => $code, 'origins' => ['ads.example']]]]);
            listsPublish($content, $this->admin);
        });
    };
    $this->story = pageArticle($this->editor, 'A story');
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('puts a placeholder in the page and none of the region’s content, scripts included', function (): void {
    ($this->place)($this->ad);

    $html = (string) addrGet('/a-story/')->assertOk()->getContent();

    expect($html)->toContain('data-teme-region-min="1440"')
        ->toContain('data-teme-region-src="/_teme/region?name=half-page&amp;lang=')
        ->not->toContain('Half page ad')
        ->not->toContain('window.halfPage')
        ->not->toContain('https://ads.example/a.js');
});

it('carries core’s regions script once, whatever the number of placeholders', function (): void {
    ($this->place)($this->ad);

    expect(substr_count((string) addrGet('/a-story/')->getContent(), 'data-teme="regions"'))->toBe(1);
});

it('draws a region with no width as before, and carries no script for it', function (): void {
    ($this->place)($this->ad, 'after-content');

    $html = (string) addrGet('/a-story/')->assertOk()->getContent();

    expect($html)->toContain('Half page ad')->not->toContain('data-teme-region-min')->not->toContain('data-teme="regions"');
});

it('draws nothing, no placeholder and no script, for a region with nothing published', function (): void {
    $html = (string) addrGet('/a-story/')->assertOk()->getContent();

    expect($html)->not->toContain('data-region="half-page"')->not->toContain('data-teme="regions"');
});

it('answers the region’s own content at its fragment address, and the page’s policy already allows its scripts', function (): void {
    ($this->place)($this->ad);

    $page = addrGet('/a-story/')->assertOk();
    $fragment = addrGet('/_teme/region?name=half-page&lang=en')->assertOk();
    $policy = PolicyHeaders::of($page);

    expect($fragment->headers->get('Content-Type'))->toContain('text/html')
        ->and((string) $fragment->getContent())->toContain('Half page ad')->toContain('window.halfPage=1')->toContain('https://ads.example/a.js')
        ->and(PolicyHeaders::sources($policy, 'script-src'))->toContain(PagePolicy::hash('window.halfPage=1'), 'https://ads.example');
});

it('answers 404 for a region with no width, an unknown one, an empty one and a missing name', function (): void {
    ($this->place)($this->ad, 'after-content');

    foreach (['name=after-content', 'name=nothing', 'name=half-page', ''] as $query) {
        addrGet('/_teme/region?'.$query)->assertNotFound();
    }
});

it('reads a language that isn’t one of the site’s as the default’s', function (): void {
    ($this->place)($this->ad);

    expect((string) addrGet('/_teme/region?name=half-page&lang=zz')->assertOk()->getContent())->toContain('Half page ad');
});

it('is stored by the page cache like a page, apart by name and language, and purged when the region changes', function (): void {
    $this->folder = cacheOn();
    $this->freezeSecond();
    ($this->place)($this->ad);

    cacheGet('/_teme/region?name=half-page&lang=en')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet('/_teme/region?name=half-page&lang=en')->assertHeader('X-Teme-Cache', 'HIT');
    cacheGet('/_teme/region?name=half-page&lang=fr')->assertHeader('X-Teme-Cache', 'MISS');
    expect(cacheEntry('/_teme/region?name=half-page&lang=en')?->expiresAt - now()->getTimestamp())->toBeGreaterThan(30);

    $region = Content::query()->where('type', 'region')->where('slug', 'wide--half-page')->firstOrFail();
    scElevated(function () use ($region): void {
        regionSave($region, $this->admin, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Changed ad']]]]);
        listsPublish($region, $this->admin);
    });

    expect((string) cacheGet('/_teme/region?name=half-page&lang=en')->assertHeader('X-Teme-Cache', 'MISS')->getContent())->toContain('Changed ad');
});

it('accepts a minWidth of 200 to 4000 pixels and refuses any other', function (mixed $width, bool $valid): void {
    $manifest = json_decode((string) file_get_contents(base_path('tests/Fixtures/themes/wide/teme-theme.json')), true);
    $manifest['regions']['half-page']['minWidth'] = $width;
    $errors = array_values(array_filter(app(ManifestValidator::class)->errors($manifest, base_path('tests/Fixtures/themes/wide')), fn (string $error): bool => str_contains($error, 'minWidth')));

    expect($errors === [])->toBe($valid);
})->with([[1440, true], [200, true], [4000, true], [199, false], [4001, false], ['1440', false], [1440.5, false]]);
