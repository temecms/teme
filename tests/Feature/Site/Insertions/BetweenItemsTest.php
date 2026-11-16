<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Insertions\PagePosition;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('billboard');
    // Core's theme draws between items and the after-content region, and accepts the fixtures' Teme version.
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();

    $this->author = User::factory()->create()->assignRole('author');
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
});

it('draws after the third item and after every fourth, never after the last, on a term\'s archive', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);

    foreach (range(1, 8) as $i) {
        pageArticle($this->author, "Story {$i}", categories: [$news]);
    }

    $html = (string) addrGet('/category/news/')->assertOk()->getContent();

    expect(substr_count($html, 'data-test="billboard-after-3"'))->toBe(1)
        ->and(substr_count($html, 'data-test="billboard-every-4"'))->toBe(1)
        ->and($html)->toContain('<div class="teme-between-items" style="grid-column:1/-1"><div data-test="billboard-after-3">After three</div></div>');
});

it('draws between items on the home page and on a search', function (): void {
    foreach (range(1, 9) as $i) {
        searchPublished($this->author, "Harbour story {$i}", 'Boats in the harbour.');
    }

    expect((string) addrGet('/')->assertOk()->getContent())->toContain('data-test="billboard-after-3"')
        ->and((string) addrGet('/?s=harbour')->assertOk()->getContent())->toContain('data-test="billboard-after-3"');
});

it('counts every few items to its ceiling, and never past the last item', function (int $n, bool $last, bool $drawn): void {
    expect(PageInsertions::afterItem(PagePosition::everyItems(2, 3), $n, $last))->toBe($drawn);
})->with([
    'the second' => [2, false, true],
    'the third' => [3, false, false],
    'the sixth' => [6, false, true],
    'the eighth, past the ceiling' => [8, false, false],
    'the fourth, the last' => [4, true, false],
]);

it('puts the line in the default theme\'s list templates', function (string $template): void {
    expect((string) file_get_contents(base_path("themes/default/views/templates/{$template}.blade.php")))
        ->toContain('<x-teme::between-items :after="$loop->iteration" :last="$loop->last" />');
})->with(['archive', 'latest', 'search']);
