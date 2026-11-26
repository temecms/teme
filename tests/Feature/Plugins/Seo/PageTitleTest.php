<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Head\HasPageTitle;
use Teme\Contracts\Head\HeadPage;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Seokit\Seokit;

require_once __DIR__.'/Seokit.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
});

afterEach(function (): void {
    Seokit::reset();
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
    $core = headValue(headDom('/harbour-news/'), '//head/title');
    $asked = 0;
    Seokit::$title = function () use ($clock, &$asked): string {
        $asked++;
        $clock->now += 150_000_000;

        return 'Too late';
    };

    $first = headValue(headDom('/harbour-news/'), '//head/title');

    expect($first)->toBe($core)
        ->and($asked)->toBe(1)
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/seokit#head-title']);
});
