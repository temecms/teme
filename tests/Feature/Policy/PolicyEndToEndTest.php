<?php

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\SignedInMarker;
use App\Domain\Comments\Models\Comment;
use App\Domain\Design\Operations\ActivateTheme;
use App\Domain\Design\Operations\Input\ActivateThemeInput;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Domain\Themes\ActiveTheme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('sends the same policy on a MISS, a HIT and a STALE copy', function (): void {
    config(['teme.public_csp' => 'enforce']);
    Sleep::fake(syncWithCarbon: true);

    $miss = addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $hit = addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
    $this->travel(3)->seconds();
    app(PageCache::class)->invalidate(['list:post']);
    cacheDrawing('/');
    $stale = addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'STALE');

    $policy = (string) $miss->headers->get('Content-Security-Policy');

    expect($policy)->not->toBe('')
        ->and(PolicyHeaders::hashes($policy))->not->toBe([])
        ->and($hit->headers->get('Content-Security-Policy'))->toBe($policy)
        ->and($stale->headers->get('Content-Security-Policy'))->toBe($policy)
        ->and($miss->headers->get('Reporting-Endpoints'))->toBe('teme-csp="http://localhost/_teme/csp"')
        ->and($hit->headers->get('Reporting-Endpoints'))->toBe($miss->headers->get('Reporting-Endpoints'))
        ->and($stale->headers->get('Reporting-Endpoints'))->toBe($miss->headers->get('Reporting-Endpoints'));
});

it('keeps the policy with the file store and with the Redis store', function (string $store): void {
    $prefix = $store === 'redis' ? cacheOnRedis() : null;

    try {
        $miss = addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');
        $hit = addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'HIT');
    } finally {
        cacheOffValkey($prefix);
    }

    $policy = PolicyHeaders::of($miss);

    expect($policy)->not->toBe('')
        ->and(PolicyHeaders::hashes($policy))->not->toBe([])
        ->and(PolicyHeaders::of($hit))->toBe($policy)
        ->and($hit->headers->get('Reporting-Endpoints'))->toBe('teme-csp="http://localhost/_teme/csp"');
})->with(['file', 'redis']);

it('gives a page drawn for a signed-in browser its own policy, unstored', function (): void {
    $reader = addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');
    $signedIn = cacheGet('/harbour-news/', [SignedInMarker::COOKIE => '1'])->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (signed in)');
    $html = (string) $reader->getContent();

    preg_match('#<script type="speculationrules">(.+?)</script>#s', $html, $rules);

    expect($rules[1] ?? '')->not->toBe('')
        ->and(PolicyHeaders::hashes(PolicyHeaders::of($reader)))->toContain(PagePolicy::hash($rules[1]))
        ->and(PolicyHeaders::of($signedIn))->not->toBe('')
        ->and(PolicyHeaders::hashes(PolicyHeaders::of($signedIn)))->not->toContain(PagePolicy::hash($rules[1]))
        ->and(PolicyHeaders::hashes(PolicyHeaders::of($signedIn)))->toHaveCount(count(PolicyHeaders::hashes(PolicyHeaders::of($reader))) - 1);
});

it('never adds the hash of a script injected into a page', function (string $mode): void {
    config(['teme.public_csp' => $mode, 'teme.theme_paths' => [base_path('tests/Fixtures/themes/csp-origins')]]);
    themeActivate('csp-origins');
    $injected = addrPublished($this->author, '<script>alert(1)</script>');
    $path = '/'.addrCurrent('content', $injected->id).'/';

    foreach (['MISS', 'HIT'] as $answer) {
        $response = addrGet($path)->assertOk()->assertHeader('X-Teme-Cache', $answer);
        $html = (string) $response->getContent();
        $core = [];

        foreach (dtDom($html)->query('//script[@data-teme or @type="speculationrules"]') as $script) {
            $core[] = PagePolicy::hash($script->textContent);
        }

        expect($html)->toContain('<script>alert(1)</script>')
            ->and($core)->not->toBe([])
            ->and(PolicyHeaders::hashes(PolicyHeaders::of($response)))->toEqualCanonicalizing($core)
            ->not->toContain(PagePolicy::hash('alert(1)'));
    }
})->with(['report-only', 'enforce']);

it('never adds the hash of a script a reader writes in a comment', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $submitted = cmSubmit($this->item, '<script>alert(2)</script>');

    if ($submitted->status !== 'approved') {
        cmModerate(Comment::query()->findOrFail($submitted->commentId), 'approved', $admin);
    }

    $response = addrGet('/harbour-news/')->assertOk();
    $html = (string) $response->getContent();
    $scripts = [];

    foreach (dtDom($html)->query('//script') as $script) {
        $scripts[] = $script->textContent;
    }

    expect($html)->toContain('&lt;script&gt;alert(2)&lt;/script&gt;')
        ->and($scripts)->not->toContain('alert(2)')
        ->and(PolicyHeaders::hashes(PolicyHeaders::of($response)))->not->toBe([])
        ->not->toContain(PagePolicy::hash('alert(2)'));
});

it("drops site code's hashes and origins from every stored page when the entry is switched off", function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $entry = scAdd($admin, 'Inline', 'head', '<script>tracked()</script><img src="https://px.example.net/p.gif" alt="">');

    $stored = addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');

    expect(PolicyHeaders::of($stored))->toContain(PagePolicy::hash('tracked()'))->toContain('https://px.example.net')
        ->and(PolicyHeaders::of(addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'HIT')))->toContain(PagePolicy::hash('tracked()'));

    scElevated(fn (): array => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($entry->id, false, (string) $admin->id)));

    $after = addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');

    expect(PolicyHeaders::of($after))->not->toBe('')
        ->not->toContain(PagePolicy::hash('tracked()'))
        ->not->toContain('https://px.example.net');
});

it("gives a page drawn after a theme switch the new theme's origins", function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/csp-origins')]]);
    themeActivate('default');
    $admin = User::factory()->create()->assignRole('administrator');

    expect(PolicyHeaders::of(addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS')))->not->toContain('https://fonts.example.com');

    app(ActivateTheme::class)->execute(new ActivateThemeInput('csp-origins', (string) $admin->id));
    // As the next request finds it: this one application drew the old theme already.
    app(ActiveTheme::class)->forget();

    expect(PolicyHeaders::sources(PolicyHeaders::of(addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS')), 'font-src'))->toContain('https://fonts.example.com');
});

it('serves the static file with the same policy the header carries, in enforce mode', function (): void {
    cacheOff($this->folder);
    $this->folder = cacheOnStatic();
    app()->detectEnvironment(fn (): string => 'production');
    config(['teme.public_csp' => 'enforce']);

    $response = addrGet('/harbour-news/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $header = (string) $response->headers->get('Content-Security-Policy');
    $file = (string) cacheStaticFile('/harbour-news/');
    $meta = dtDom($file)->query('//meta[@http-equiv="Content-Security-Policy"]')->item(0)?->getAttribute('content');

    expect($header)->not->toBe('')
        ->and($file)->not->toBe('')
        ->and($meta)->not->toBeNull()->not->toBe('')
        ->and($meta)->toBe(PagePolicy::forMeta($header));
});
