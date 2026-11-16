<?php

declare(strict_types=1);

use App\Domain\Site\Policy\PagePolicy;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\DeleteSiteCode;
use App\Domain\SiteCode\Operations\Input\DeleteSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->ad = '<script src="https://ads.example/a.js"></script><script>window.x=1</script><div onclick="go()">Ad</div>';
    $this->folder = null;

    // The default theme's region after the article, published with one Custom HTML card placing this entry.
    $this->place = function (SiteCodeEntry $entry): void {
        $region = regionCreate($this->admin, 'default');
        regionSave($region, $this->editor, [['type' => 'site-code', 'attrs' => ['id' => (string) Str::ulid(), 'entryId' => $entry->id]]]);
        listsPublish($region, $this->editor);
    };
    $this->story = pageArticle($this->editor, 'A story');
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('draws the placed code exactly as written, and gives the page its hashes and hosts (I-3375)', function (): void {
    ($this->place)(scAdd($this->admin, 'Sidebar ad', 'placed', $this->ad));

    $response = addrGet('/a-story/')->assertOk();
    $policy = PolicyHeaders::of($response);

    expect((string) $response->getContent())->toContain('<div class="card-site-code">'.$this->ad.'</div>')
        ->and(substr_count((string) $response->getContent(), 'window.x=1'))->toBe(1)
        ->and(PolicyHeaders::sources($policy, 'script-src'))->toContain(PagePolicy::hash('window.x=1'), PagePolicy::hash('go()'), "'unsafe-hashes'", 'https://ads.example');
});

it('draws nothing, and fails nothing, for code that is off, deleted, or not placed with a card', function (string $case): void {
    $entry = scAdd($this->admin, 'Sidebar ad', $case === 'head' ? 'head' : 'placed', $case === 'head' ? '<div data-test="head-code"></div>' : $this->ad);
    ($this->place)($entry);

    match ($case) {
        'off' => scElevated(fn (): array => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($entry->id, false, (string) $this->admin->id))),
        'deleted' => scElevated(fn (): array => app(DeleteSiteCode::class)->execute(new DeleteSiteCodeInput($entry->id, (string) $this->admin->id))),
        default => null,
    };

    $response = addrGet('/a-story/')->assertOk();

    expect((string) $response->getContent())->not->toContain('card-site-code')->not->toContain('window.x=1')
        ->and(PolicyHeaders::of($response))->not->toContain(PagePolicy::hash('window.x=1'))->not->toContain('https://ads.example');

    if ($case === 'head') {
        // Drawn in the head as head code, once, and never by the card.
        expect(substr_count((string) $response->getContent(), 'data-test="head-code"'))->toBe(1);
    }
})->with(['off', 'deleted', 'head']);

it('draws nothing while the host disallows site code', function (): void {
    ($this->place)(scAdd($this->admin, 'Sidebar ad', 'placed', $this->ad));
    config(['teme.disallow_site_code' => true]);

    $response = addrGet('/a-story/')->assertOk();

    expect((string) $response->getContent())->not->toContain('window.x=1')
        ->and(PolicyHeaders::of($response))->not->toContain(PagePolicy::hash('window.x=1'));
});

it('draws nothing in the signed-in preview, which withholds site code (I-1324)', function (): void {
    ($this->place)(scAdd($this->admin, 'Sidebar ad', 'placed', $this->ad));

    // The public page first: in a test, a scoped service outlives one request.
    expect((string) addrGet('/a-story/')->assertOk()->getContent())->toContain('window.x=1');

    expect((string) $this->actingAs($this->editor)->get(route('content.preview', ['content' => $this->story->id]))->assertOk()->getContent())
        ->not->toContain('window.x=1')
        ->not->toContain('card-site-code');
});

it('keeps the hashes on the page served again from the page cache', function (): void {
    ($this->place)(scAdd($this->admin, 'Sidebar ad', 'placed', $this->ad));
    $this->folder = cacheOn();

    addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $hit = addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect((string) $hit->getContent())->toContain('window.x=1')
        ->and(PolicyHeaders::sources(PolicyHeaders::of($hit), 'script-src'))->toContain(PagePolicy::hash('window.x=1'), 'https://ads.example');
});

it('removes the stored page when the code is switched off, once that commits, and not after a rollback (rule 6)', function (): void {
    $entry = scAdd($this->admin, 'Sidebar ad', 'placed', $this->ad);
    ($this->place)($entry);
    $this->folder = cacheOn();
    addrGet('/a-story/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $switchOff = fn (): array => scElevated(fn (): array => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($entry->id, false, (string) $this->admin->id)));

    cacheRolledBack($switchOff);

    expect(cacheKept('/a-story/'))->toBe(['/a-story/']);

    $switchOff();

    expect(cacheKept('/a-story/'))->toBe([])
        ->and((string) addrGet('/a-story/')->getContent())->not->toContain('window.x=1');
});
