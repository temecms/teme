<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Head\HasPageTitle;
use Teme\Contracts\Head\HeadPage;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Seokit\Seokit;
use Tests\Fixtures\Plugins\Titler\Titler;

require_once __DIR__.'/Seokit.php';

require_once dirname(__DIR__, 3).'/Fixtures/plugins/titler/src/Titler.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
});

afterEach(function (): void {
    Seokit::reset();
    Titler::reset();
    PluginFixtures::forget();
});

it('shows a plugin’s title in the title, the social title and the structured data’s name', function (): void {
    seokitEnabled();
    headPost($this->author, 'Harbour news');
    Seokit::$title = fn (HeadPage $page, string $core): string => "Harbour news: {$core} | Plugin";

    $dom = headDom('/harbour-news/');
    $title = headValue($dom, '//head/title');

    expect($title)->toEndWith('| Plugin')
        ->and($title)->toStartWith('Harbour news: ')
        ->and(headValue($dom, '//head/meta[@property="og:title"]/@content'))->toBe($title)
        ->and(collect(sdGraph('/harbour-news/'))->pluck('name')->filter()->all())->toContain($title);
});

it('tells the plugin core’s own title', function (): void {
    seokitEnabled();
    headPost($this->author, 'Harbour news');
    $core = headValue(headDom('/harbour-news/'), '//head/title');
    Seokit::$pages = [];
    Seokit::$title = fn (HeadPage $page, string $coreTitle): string => $coreTitle;

    headDom('/harbour-news/');

    expect($core)->toContain('Harbour news')
        ->and(Seokit::$pages[0]->kind)->toBe('article')
        ->and(headValue(headDom('/harbour-news/'), '//head/title'))->toBe($core);
});

it('keeps core’s title when the plugin answers null', function (): void {
    seokitEnabled();
    headPost($this->author, 'Harbour news');
    $core = headValue(headDom('/harbour-news/'), '//head/title');
    Seokit::$title = fn (): ?string => null;

    expect(headValue(headDom('/harbour-news/'), '//head/title'))->toBe($core);
});

it('keeps an item’s own social title when a plugin gives the title', function (): void {
    seokitEnabled();
    headPost($this->author, 'Harbour news', [BuiltInFields::SOCIAL_TITLE => 'Shared words']);
    Seokit::$title = fn (): string => 'Plugin title';

    $dom = headDom('/harbour-news/');

    expect(headValue($dom, '//head/title'))->toBe('Plugin title')
        ->and(headValue($dom, '//head/meta[@property="og:title"]/@content'))->toBe('Shared words');
});

it('refuses an empty or over-long title, records it, and keeps core’s', function (string $answer): void {
    ['faults' => $faults] = seokitEnabled();
    headPost($this->author, 'Harbour news');
    $core = headValue(headDom('/harbour-news/'), '//head/title');
    Seokit::$title = fn (): string => $answer;

    $title = headValue(headDom('/harbour-news/'), '//head/title');

    expect($title)->toBe($core)
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/seokit#head-title'])
        ->and($faults->seen[0][2])->toBe(HasPageTitle::class);
})->with([
    'empty' => ['   '],
    'too long' => [str_repeat('a', 201)],
]);

it('drops a title that comes back over its budget, records it once, and asks no more in the request', function (): void {
    ['faults' => $faults, 'clock' => $clock] = seokitEnabled();
    headPost($this->author, 'Harbour news');
    headPost($this->author, 'Ferry fares');
    $coreFirst = headValue(headDom('/harbour-news/'), '//head/title');
    $coreSecond = headValue(headDom('/ferry-fares/'), '//head/title');
    Seokit::$pages = [];
    $asked = 0;
    Seokit::$title = function () use ($clock, &$asked): string {
        $asked++;
        $clock->now += 150_000_000;

        return 'Too late';
    };

    // Two different pages in one request: the second is never asked.
    $first = headValue(headDom('/harbour-news/'), '//head/title');
    $second = headValue(headDom('/ferry-fares/'), '//head/title');

    expect($first)->toBe($coreFirst)
        ->and($second)->toBe($coreSecond)
        ->and($asked)->toBe(1)
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/seokit#head-title']);
});

it('asks nothing of a plugin that wasn’t granted pages.head', function (): void {
    seokitEnabled();
    headPost($this->author, 'Harbour news');
    $core = headValue(headDom('/harbour-news/'), '//head/title');
    Seokit::$title = fn (): string => 'Should not show';
    $granted = fn (): array => ['content.read', 'sitemaps'];
    // Revoke the grant on the record the way a narrower agreement would leave it.
    PluginRecord::query()->where('name', 'tests/seokit')->update(['consent' => json_encode(['capabilities' => $granted()])]);
    app(PluginRecords::class)->changed();
    Seokit::$pages = [];

    expect(headValue(headDom('/harbour-news/'), '//head/title'))->toBe($core)
        ->and(Seokit::$pages)->toBe([]);
});

it('lets the first enabled plugin that answers win, and a null pass to the next', function (): void {
    seokitEnabled();
    Seokit::reset();
    Titler::reset();
    $record = PluginRecord::query()->where('name', 'tests/seokit')->firstOrFail();
    PluginFixtures::use('seokit', 'titler');
    app()->instance(Elevation::class, new OperatorElevation);
    $manifest = app(PluginRegistry::class)->find('tests/titler')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/titler', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    PluginRecord::query()->where('name', 'tests/titler')->update(['enabled_at' => $record->enabled_at?->addMinute()]);
    headPost($this->author, 'Harbour news');

    Seokit::$title = fn (): string => 'From the first';
    Titler::$title = fn (): string => 'From the second';
    expect(headValue(headDom('/harbour-news/'), '//head/title'))->toBe('From the first');

    headPost($this->author, 'Ferry fares');
    Seokit::$title = fn (): ?string => null;
    expect(headValue(headDom('/ferry-fares/'), '//head/title'))->toBe('From the second');
});
