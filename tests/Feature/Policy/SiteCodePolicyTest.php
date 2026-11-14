<?php

declare(strict_types=1);

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Placement;
use App\Domain\Site\Document\Placements;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Domain\SiteCode\SiteCodeEntries;
use App\Domain\SiteCode\SiteCodePolicy;
use App\Domain\SiteCode\SiteCodeWithheld;
use App\Domain\Themes\ViewIntent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
});

it('hashes an inline script as the browser sees it, CR LF made LF', function (): void {
    $policy = SiteCodePolicy::of("<script>a();\r\nb();</script>", []);

    expect($policy['scripts'])->toBe([PagePolicy::hash("a();\nb();")])
        ->and($policy['scripts'])->not->toContain(PagePolicy::hash("a();\r\nb();"));
});

it("hashes each on* handler's value, decoded", function (): void {
    $policy = SiteCodePolicy::of('<img src="x.gif" onload="track(this, &quot;a&quot;)" ONERROR="gone()">', []);

    expect($policy['handlers'])->toBe([PagePolicy::hash('track(this, "a")'), PagePolicy::hash('gone()')])
        ->and($policy['scripts'])->toBe([]);
});

it('finds the https origins the code loads from, by directive', function (): void {
    $pasted = "<script>window.dataLayer = window.dataLayer || []; if (a < b && c > d) { go('\"x\"'); }</script>\r\n<img src=\"https://px.example.net/p.gif\" onload=\"track(this)\" alt=\"\">\n<!-- Café ✓ --></body>";
    $analytics = '<script defer data-site="ledger" src="https://stats.example.org/script.js"></script>';
    $more = '<link rel="preload stylesheet" href="//Fonts.Example.com/a.css">'
        .'<iframe src="https://maps.example.com:8443/embed?q=1"></iframe>'
        .'<form action="https://forms.example.org/post"></form>'
        .'<audio src="https://sound.example.net/a.mp3"></audio>'
        .'<video><source src="https://video.example.net/v.mp4"></video>';

    expect(SiteCodePolicy::of($pasted, [])['origins'])->toBe(['img-src' => ['https://px.example.net']])
        ->and(SiteCodePolicy::of($analytics, [])['origins'])->toBe(['script-src' => ['https://stats.example.org']])
        ->and(SiteCodePolicy::of($more, [])['origins'])->toBe([
            'form-action' => ['https://forms.example.org'],
            'frame-src' => ['https://maps.example.com:8443'],
            'media-src' => ['https://sound.example.net', 'https://video.example.net'],
            'style-src' => ['https://fonts.example.com'],
        ]);
});

it('adds nothing for http, relative or data addresses', function (): void {
    $code = '<script src="http://plain.example.org/a.js"></script><img src="/local.png"><img src="data:image/gif;base64,R0lGOD">'
        .'<iframe src="javascript:void(0)"></iframe><img src="https://user@sneaky.example.org/x.png"><link rel="icon" href="https://icons.example.org/i.png">';

    expect(SiteCodePolicy::of($code, [])['origins'])->toBe([]);
});

it('drops a found or declared origin whose host is not a plain host name, never repairing it', function (string $bad, string $host): void {
    $code = '<script src="'.$bad.'"></script><img src="'.$bad.'" alt=""><img src="https://px.example.net/p.gif" alt="">';

    expect(SiteCodePolicy::of($code, [$host])['origins'])->toBe(['img-src' => ['https://px.example.net']]);
})->with([
    'a keyword after a space' => ["https://a.com 'unsafe-eval'", "a.com 'unsafe-eval'"],
    'a second directive' => ['https://evil.com;script-src *', 'evil.com;script-src *'],
    'a second policy' => ['https://a.com,b', 'a.com,b'],
]);

it('finds no wildcard origin in code, and keeps a numeric port', function (): void {
    expect(SiteCodePolicy::of('<script src="https://*.example.com/a.js"></script><iframe src="https://maps.example.com:8443/e"></iframe>', [])['origins'])
        ->toBe(['frame-src' => ['https://maps.example.com:8443']]);
});

it("leaves a page's header exactly as it is without site code's malformed addresses", function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $admin = User::factory()->create()->assignRole('administrator');
    scAdd($admin, 'Pixel', 'head', '<img src="https://px.example.net/p.gif" alt="">');

    $before = PolicyHeaders::of(addrGet('/')->assertOk());

    scAdd($admin, 'Broken', 'body-end', "<script src=\"https://cdn.x.com 'unsafe-eval' *\"></script><script src=\"https://evil.com;script-src *\"></script><img src=\"https://a.com,b\" alt=\"\">");

    $after = PolicyHeaders::of(addrGet('/')->assertOk());

    expect($after)->toBe($before)
        ->and(PolicyHeaders::sources($after, 'img-src'))->toContain('https://px.example.net')
        ->and($after)->not->toContain("'unsafe-eval'")->not->toContain(',')
        ->and(PolicyHeaders::sources($after, 'script-src'))->not->toContain('*')
        ->and(substr_count($after, 'script-src '))->toBe(1);
});

it("hashes no script that has a src, and every one that hasn't, whatever its type", function (): void {
    $policy = SiteCodePolicy::of('<script src="https://stats.example.org/a.js">ignored()</script><script type="module">m()</script><script type="text/plain">p</script><script>s()</script>', []);

    expect($policy['scripts'])->toBe([PagePolicy::hash('m()'), PagePolicy::hash('p'), PagePolicy::hash('s()')]);
});

it('puts each declared host in every fetch directive and form-action', function (): void {
    $origins = SiteCodePolicy::of('<p>Hello</p>', ['plausible.io', '*.example.com'])['origins'];

    expect(array_keys($origins))->toEqualCanonicalizing(PagePolicy::DECLARED);

    foreach (PagePolicy::DECLARED as $directive) {
        expect($origins[$directive])->toBe(['https://*.example.com', 'https://plausible.io']);
    }
});

it('gives a page the hashes and origins of the entries that are on, and none of one that is off', function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $admin = User::factory()->create()->assignRole('administrator');
    scAdd($admin, 'Inline', 'head', '<script>on()</script><img src="https://px.example.net/p.gif" onload="seen()">');
    $off = scAdd($admin, 'Off', 'body-end', '<script>off()</script><script src="https://off.example.org/a.js"></script>');
    scElevated(fn (): array => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($off->id, false, (string) $admin->id)));

    $policy = PolicyHeaders::of(addrGet('/')->assertOk());

    expect(PolicyHeaders::sources($policy, 'script-src'))->toContain(PagePolicy::hash('on()'), "'unsafe-hashes'", PagePolicy::hash('seen()'))
        ->and(PolicyHeaders::sources($policy, 'img-src'))->toContain('https://px.example.net')
        ->and($policy)->not->toContain(PagePolicy::hash('off()'))
        ->and($policy)->not->toContain('https://off.example.org');
});

it("gives a page none of site code's while the host disallows it or the page withholds it", function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $admin = User::factory()->create()->assignRole('administrator');
    scAdd($admin, 'Inline', 'head', '<script>on()</script>');

    expect(PolicyHeaders::of(addrGet('/')->assertOk()))->toContain(PagePolicy::hash('on()'));

    config(['teme.disallow_site_code' => true]);

    expect(PolicyHeaders::of(addrGet('/')->assertOk()))->not->toContain(PagePolicy::hash('on()'));

    config(['teme.disallow_site_code' => false]);
    $policy = app(PagePolicy::class);
    $page = new DocumentPage(new ViewIntent('latest'), null);

    $policy->start();
    app(Placements::class)->for(Placement::Head, $page);

    expect($policy->header('http://localhost'))->toContain(PagePolicy::hash('on()'));

    app(SiteCodeWithheld::class)->withhold();
    $policy->start();

    foreach (Placement::cases() as $placement) {
        app(Placements::class)->for($placement, $page);
    }

    expect($policy->header('http://localhost'))->not->toContain(PagePolicy::hash('on()'));
});

it('works out the policy once per change, not per page', function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $admin = User::factory()->create()->assignRole('administrator');
    scAdd($admin, 'Inline', 'head', '<script>on()</script>');

    addrGet('/')->assertOk();

    $cached = Cache::memo()->get('site-code:on:2');

    expect(SiteCodeEntries::CACHE_KEY)->toBe('site-code:on:2')
        ->and($cached['head'][0]['policy']['scripts'] ?? null)->toBe([PagePolicy::hash('on()')]);

    $read = 0;
    DB::listen(function ($query) use (&$read): void {
        $read += str_contains($query->sql, 'site_code_entries') ? 1 : 0;
    });

    expect(PolicyHeaders::of(addrGet('/')->assertOk()))->toContain(PagePolicy::hash('on()'))
        ->and($read)->toBe(0);
});
