<?php

declare(strict_types=1);

use App\Domain\Cards\LinkResolver;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Site\Links\ArticleLinkAttributes;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Content\ArticleLinks;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    hkRecordFaults();

    /** Enables the linker fixture, or a copy that starts another of its classes, and starts it. */
    $this->start = function (?string $class = null): void {
        $path = $class === null ? 'linker' : PluginFixtures::copy('linker', ['plugin' => 'Tests\\Fixtures\\Plugins\\Linker\\'.$class]);
        PluginFixtures::use($path);
        $manifest = app(PluginRegistry::class)->find('tests/linker')?->manifest;

        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/linker', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
        app(PluginLoader::class)->load();
    };

    $this->author = User::factory()->create()->assignRole('author');
    $this->publish = fn () => pageArticleFromDocument($this->author, 'Link story', [[
        'type' => 'paragraph',
        'content' => [
            ['type' => 'text', 'text' => 'Outside', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org/page']]]],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'text', 'text' => 'inside', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/about/']]]],
        ],
    ]]);
    $this->page = fn (): string => (string) addrGet('/link-story/')->assertOk()->getContent();
});

afterEach(fn () => PluginFixtures::forget());

it('lets a plugin mark the links that lead outside the site nofollow and open them in a new tab, on the page and in the feed', function (): void {
    ($this->start)();
    ($this->publish)();

    expect(($this->page)())->toContain('<a href="https://example.org/page" target="_blank" rel="noopener nofollow">Outside</a>')
        ->toContain('<a href="/about/">inside</a>')
        ->not->toContain('external-link')
        ->and((string) addrGet('/feed/')->assertOk()->getContent())->toContain('href="https://example.org/page" target="_blank" rel="noopener nofollow"')
        ->and(hkFaults())->toBe([]);
});

it('changes a link to the site only when the plugin names its host', function (): void {
    ($this->start)('LinkerOwnHost');
    ($this->publish)();

    expect(($this->page)())->toContain('<a href="https://example.org/page" rel="noopener sponsored">Outside</a>')
        ->toContain('<a href="/about/" rel="sponsored">inside</a>');
});

it('ignores and records a value outside the list, or a plugin that fails, and leaves the links as they were', function (string $class, string $fault): void {
    ($this->start)($class);
    ($this->publish)();

    expect(($this->page)())->toContain('<a href="https://example.org/page" rel="noopener">Outside</a>')
        ->toContain('<a href="/about/">inside</a>')
        ->and(array_column(hkFaults(), 'owner'))->toContain('tests/linker')
        ->and(collect(hkFaults())->firstWhere('hook', ArticleLinks::class)['message'] ?? '')->toContain($fault);
})->with([
    'a rel outside the list' => ['LinkerBadValue', 'rel "me" isn’t one Teme writes'],
    'a plugin that fails' => ['LinkerThrows', 'Linker lost its rules.'],
]);

it('asks nothing of a plugin without content.links', function (): void {
    PluginFixtures::use(PluginFixtures::copy('linker', ['capabilities' => []]));

    expect(app(PluginRegistry::class)->find('tests/linker')?->problems)->toContain('ArticleLinks needs content.links, which it doesn’t ask for.');
});

it('leaves the links as they were and records a plugin that takes over its budget for an article', function (): void {
    ($this->start)();
    $content = ($this->publish)();
    // Every read of the clock moves it on by just over a call's budget.
    app()->instance(ColumnClock::class, new class implements ColumnClock
    {
        private int $now = 0;

        public function nanoseconds(): int
        {
            return $this->now += (PageInsertions::CALL_BUDGET_MS + 1) * 1_000_000;
        }
    });
    app()->forgetScopedInstances();

    $document = (array) ContentRevision::query()->findOrFail($content->published_revision_id)->document;

    expect(app(ArticleLinkAttributes::class)->for($document, app(LinkResolver::class)))->toBe([])
        ->and(collect(hkFaults())->firstWhere('hook', ArticleLinks::class))->toMatchArray([
            'owner' => 'tests/linker',
            'message' => 'tests/linker\'s link attributes took 101 ms, over the 100 ms budget.',
        ]);
});

it('stops asking once the page’s budget is spent, and records the asks it skipped', function (): void {
    ($this->start)();
    $content = ($this->publish)();
    // Each ask takes its whole budget, never over it; three of them spend the page's.
    app()->instance(ColumnClock::class, new class implements ColumnClock
    {
        private int $now = 0;

        public function nanoseconds(): int
        {
            return $this->now += PageInsertions::CALL_BUDGET_MS * 1_000_000;
        }
    });
    app()->forgetScopedInstances();

    $document = (array) ContentRevision::query()->findOrFail($content->published_revision_id)->document;
    $asks = array_map(fn (): array => app(ArticleLinkAttributes::class)->for($document, app(LinkResolver::class)), range(1, 4));

    expect(array_map(fn (array $kept): int => count($kept), $asks))->toBe([1, 1, 1, 0])
        ->and(array_column(hkFaults(), 'message'))->toBe(['tests/linker\'s link attributes took 300 ms, over the 250 ms budget.']);
});
