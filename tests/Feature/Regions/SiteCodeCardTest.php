<?php

declare(strict_types=1);

use App\Domain\Site\Policy\PagePolicy;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

/*
 * The Custom HTML card's code on the public site: drawn exactly as written,
 * its scripts and hosts given to the page's policy, and nothing while it is
 * empty, the host disallows site code, or the preview withholds it.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->ad = '<script src="https://ads.example/a.js"></script><script>window.x=1</script><div onclick="go()">Ad</div>';
    $this->folder = null;
    $this->region = regionCreate($this->admin, 'default');
    $this->id = (string) Str::ulid();

    // The default theme's region after the article, published by an administrator with one Custom HTML card holding this code.
    $this->place = function (string $code, array $origins = []): void {
        scElevated(function () use ($code, $origins): void {
            regionSave($this->region, $this->admin, [['type' => 'site-code', 'attrs' => ['id' => $this->id, 'code' => $code, 'origins' => $origins]]]);
            listsPublish($this->region, $this->admin);
        });
    };
    $this->story = pageArticle($this->editor, 'A story');
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('draws the card’s code exactly as written, and gives the page its hashes and hosts', function (): void {
    ($this->place)($this->ad, ['cdn.ads.example']);

    $response = addrGet('/a-story/')->assertOk();
    $policy = PolicyHeaders::of($response);

    expect((string) $response->getContent())->toContain('<div class="card-site-code">'.$this->ad.'</div>')
        ->and(substr_count((string) $response->getContent(), 'window.x=1'))->toBe(1)
        ->and(PolicyHeaders::sources($policy, 'script-src'))->toContain(PagePolicy::hash('window.x=1'), PagePolicy::hash('go()'), "'unsafe-hashes'", 'https://ads.example')
        ->and($policy)->toContain('cdn.ads.example');
});

it('draws nothing, and fails nothing, for a card that holds no code', function (): void {
    ($this->place)('');

    $response = addrGet('/a-story/')->assertOk();

    expect((string) $response->getContent())->not->toContain('card-site-code')->not->toContain('window.x=1')
        ->and(PolicyHeaders::of($response))->not->toContain(PagePolicy::hash('window.x=1'))->not->toContain('https://ads.example');
});

it('draws nothing while the host disallows site code', function (): void {
    ($this->place)($this->ad);
    config(['teme.disallow_site_code' => true]);

    $response = addrGet('/a-story/')->assertOk();

    expect((string) $response->getContent())->not->toContain('window.x=1')
        ->and(PolicyHeaders::of($response))->not->toContain(PagePolicy::hash('window.x=1'));
});

it('draws nothing in the signed-in preview, which withholds site code (I-1324)', function (): void {
    ($this->place)($this->ad);

    // The public page first: in a test, a scoped service outlives one request.
    expect((string) addrGet('/a-story/')->assertOk()->getContent())->toContain('window.x=1');

    expect((string) $this->actingAs($this->editor)->get(route('content.preview', ['content' => $this->story->id]))->assertOk()->getContent())
        ->not->toContain('window.x=1')
        ->not->toContain('card-site-code');
});

it('keeps the hashes on the page served again from the page cache', function (): void {
    ($this->place)($this->ad);
    $this->folder = cacheOn();

    addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $hit = addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect((string) $hit->getContent())->toContain('window.x=1')
        ->and(PolicyHeaders::sources(PolicyHeaders::of($hit), 'script-src'))->toContain(PagePolicy::hash('window.x=1'), 'https://ads.example');
});

it('removes the stored page when a published change removes the card', function (): void {
    ($this->place)($this->ad);
    $this->folder = cacheOn();
    addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    scElevated(function (): void {
        regionSave($this->region, $this->admin, []);
        listsPublish($this->region, $this->admin);
    });

    expect(cacheKept('/a-story/'))->toBe([])
        ->and((string) addrGet('/a-story/')->getContent())->not->toContain('window.x=1');
});
