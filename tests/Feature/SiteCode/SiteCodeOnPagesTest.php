<?php

declare(strict_types=1);

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Fragment;
use App\Domain\Site\Document\Placement;
use App\Domain\Site\Document\Precedence;
use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\SaveSiteCode;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Domain\SiteCode\SiteCodeFragments;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    cmItem(User::factory()->create(['name' => 'Jane Doe'])->assignRole('author'), 'Harbour news');
    $this->folder = null;
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('draws each entry that is on exactly as written, in its placement and in the order added, and none that is off (I-288)', function (): void {
    scAdd($this->admin, 'Analytics', 'head', "<script data-test=\"head\">if (a < b && c) { go('\"x\"'); }</script>");
    scAdd($this->admin, 'Tag manager', 'body-start', '<noscript data-test="start"><iframe src="https://tags.example.net/ns" onload="x()"></iframe></noscript>');
    scAdd($this->admin, 'Chat', 'body-end', "<div data-test=\"end-1\"></div>\r\n<!-- Café ✓ -->");
    scAdd($this->admin, 'Survey', 'body-end', '<div data-test="end-2"></div>');
    scAdd($this->admin, 'Old pixel', 'head', '<img data-test="off" alt="">', on: false);

    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();
    $at = fn (string $needle): int => (int) strpos($html, $needle);

    expect($html)->toContain("<script data-test=\"head\">if (a < b && c) { go('\"x\"'); }</script>")
        ->toContain('<noscript data-test="start"><iframe src="https://tags.example.net/ns" onload="x()"></iframe></noscript>')
        ->toContain("<div data-test=\"end-1\"></div>\r\n<!-- Café ✓ -->")
        ->not->toContain('data-test="off"')
        ->and(substr_count($html, 'data-test="head"'))->toBe(1)
        ->and($at('data-test="head"'))->toBeGreaterThan($at('<style>@layer'))->toBeLessThan($at('</head>'))
        ->and($at('data-test="start"'))->toBeGreaterThan($at('class="teme-skip-link"'))->toBeLessThan($at('<main'))
        ->and($at('data-test="end-1"'))->toBeGreaterThan($at('</main>'))->toBeLessThan($at('data-test="end-2"'))
        ->and($at('data-test="end-2"'))->toBeLessThan($at('data-teme="session"'));
});

it('gives each entry that is on as one fragment at the site\'s precedence, keyed by the entry, for its own placement only', function (): void {
    $head = scAdd($this->admin, 'Analytics', 'head', '<script data-test="head"></script>');
    scAdd($this->admin, 'Chat', 'body-end', '<div data-test="end"></div>');
    $contributor = app(SiteCodeFragments::class);

    expect($contributor->precedence())->toBe(Precedence::Site)
        ->and(array_map(fn (Fragment $fragment): array => [$fragment->key, $fragment->html], $contributor->fragments(Placement::Head, new DocumentPage(null, null))))
        ->toBe([['site-code.'.$head->id, '<script data-test="head"></script>']])
        ->and($contributor->fragments(Placement::BodyStart, new DocumentPage(null, null)))->toBe([]);
});

it('draws nothing while the host disallows site code, though every entry is kept (I-866)', function (): void {
    scAdd($this->admin, 'Analytics', 'head', '<script data-test="head"></script>');
    scAdd($this->admin, 'Chat', 'body-end', '<div data-test="end"></div>');
    config(['teme.disallow_site_code' => true]);

    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();

    expect($html)->not->toContain('data-test="head"')->not->toContain('data-test="end"')
        ->and(app(SiteCodeFragments::class)->fragments(Placement::BodyEnd, new DocumentPage(null, null)))->toBe([])
        ->and(DB::table('site_code_entries')->count())->toBe(2);
});

it('reads the entries once, not once a placement or once a page, and afresh after a change', function (): void {
    $entry = scAdd($this->admin, 'Analytics', 'head', '<script data-test="first"></script>');
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads += str_contains($query->sql, 'site_code_entries') ? 1 : 0;
    });

    addrGet('/harbour-news/')->assertOk();
    addrGet('/harbour-news/')->assertOk();

    expect($reads)->toBe(1);

    scElevated(fn (): array => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput($entry->id, 'Analytics', 'head', '<script data-test="second"></script>', true, (string) $this->admin->id)));

    expect((string) addrGet('/harbour-news/')->getContent())->toContain('data-test="second"')->not->toContain('data-test="first"');
});

it('removes every stored page when what pages draw changes, once the change commits, and none after a rollback or for an entry off throughout (I-263)', function (): void {
    $entry = scAdd($this->admin, 'Old pixel', 'head', '<img data-test="pixel" alt="">', on: false);
    $this->folder = cacheOn();
    addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/')->assertHeader('X-Teme-Cache', 'MISS');
    $switchOn = fn (): array => scElevated(fn (): array => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($entry->id, true, (string) $this->admin->id)));

    // Renamed while off: no page drew it before, and none draws it after.
    scElevated(fn (): array => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput($entry->id, 'Pixel', 'head', '<img data-test="pixel" alt="">', false, (string) $this->admin->id)));

    expect(cacheKept('/harbour-news/', '/'))->toBe(['/harbour-news/', '/']);

    cacheRolledBack($switchOn);

    expect(cacheKept('/harbour-news/', '/'))->toBe(['/harbour-news/', '/']);

    $switchOn();

    expect(cacheKept('/harbour-news/', '/'))->toBe([])
        ->and((string) addrGet('/harbour-news/')->getContent())->toContain('<img data-test="pixel" alt="">');
});
