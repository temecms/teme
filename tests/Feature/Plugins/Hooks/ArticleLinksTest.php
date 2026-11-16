<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
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
