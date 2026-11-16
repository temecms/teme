<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\ContributorTooSlow;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Themes\ComposerRegistry;
use App\Domain\Themes\ViewIntent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Insertions\PageFacts;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PageInsertionContributor;
use Teme\Contracts\Insertions\PagePosition;
use Tests\Fixtures\Api\Switches;
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

/**
 * A page started as PublicController starts one, for a contributor bound
 * in this scope, with the template kind the page is drawn by.
 */
function piStart(ViewIntent $intent): PageInsertions
{
    $insertions = app(PageInsertions::class);
    $insertions->start(request(), ['kind' => 'view', 'intent' => $intent, 'pageNumber' => 1], 'en');
    app(ComposerRegistry::class)->startPage($intent);

    return $insertions;
}

/**
 * A contributor that gives these insertions, noting each time it is asked.
 *
 * @param  list<PageInsertion>  $insertions
 */
function piContributor(array $insertions, ?Closure $before = null): PageInsertionContributor
{
    return new class($insertions, $before) implements PageInsertionContributor
    {
        public int $asked = 0;

        /** @param  list<PageInsertion>  $insertions */
        public function __construct(private array $insertions, private ?Closure $before) {}

        public function pageInsertions(PageFacts $page): array
        {
            $this->asked++;

            if ($this->before !== null) {
                ($this->before)();
            }

            return $this->insertions;
        }
    };
}

it('asks a plugin granted pages.insert with each kind of page\'s facts', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    $story = pageArticle($this->author, 'Harbour story', categories: [$news]);
    pageArticle($this->author, 'Second story', categories: [$news]);
    $year = $story->published_at?->format('Y') ?? date('Y');

    foreach (['/', '/harbour-story/', '/category/news/', "/{$year}/", '/?s=story'] as $address) {
        addrGet($address)->assertOk();
    }

    $asked = array_map(fn (PageFacts $facts): array => [$facts->kind, $facts->path], Billboard::$asked);
    $article = Billboard::$asked[1];

    expect($asked)->toBe([['home', '/'], ['article', '/harbour-story/'], ['term', '/category/news/'], ['date', "/{$year}/"], ['search', '/']])
        ->and($article->contentId)->toBe((string) $story->id)
        ->and($article->type)->toBe('post')
        ->and($article->termIdsByTaxonomy)->toBe(['category' => [(string) $news->id]])
        ->and($article->authorIds)->not->toBe([])
        ->and($article->pageNumber)->toBe(1)
        ->and($article->language)->not->toBe('')
        ->and(Billboard::$asked[2]->termIdsByTaxonomy)->toBe(['category' => [(string) $news->id]])
        ->and(Billboard::$asked[2]->contentId)->toBeNull();
});

it('never asks on a page not found, a preview, a feed, the .md version or the API', function (): void {
    Switches::llmsOn();
    Switches::apiOn();
    $story = pageArticle($this->author, 'Harbour story');
    $preview = histPreview($story, $this->author);

    addrGet('/nothing-here/')->assertNotFound();
    $this->get("/preview/{$preview->token}")->assertOk();
    addrGet('/feed/')->assertOk();
    addrGet('/harbour-story.md')->assertOk();
    $this->getJson('/api/v1/items/'.$story->id)->assertOk();

    expect(Billboard::$asked)->toBe([]);
});

it('asks a bound contributor only once it was granted pages.insert', function (): void {
    $contributor = piContributor([new PageInsertion('bound.top', PagePosition::bodyStart(), '<p>Top</p>')]);
    $insertions = piStart(new ViewIntent('latest', isFront: true));

    $insertions->bind($contributor, []);

    expect($insertions->at(PagePosition::BODY_START))->toHaveCount(1)
        ->and($contributor->asked)->toBe(0);

    $insertions->bind($contributor, ['pages.insert']);

    expect(array_column($insertions->at(PagePosition::BODY_START), 'owner'))->toBe(['tests/billboard', $contributor::class])
        ->and($contributor->asked)->toBe(1);
});

it('keeps the first insertion for a key, by plugin code order', function (): void {
    Billboard::$gives = [new PageInsertion('shared', PagePosition::bodyStart(), '<p>Billboard</p>')];
    $insertions = piStart(new ViewIntent('latest', isFront: true));
    $insertions->bind(piContributor([new PageInsertion('shared', PagePosition::bodyStart(), '<p>Bound</p>')]), ['pages.insert']);

    $kept = $insertions->at(PagePosition::BODY_START);

    expect($kept)->toHaveCount(1)
        ->and($kept[0]['insertion']->html)->toBe('<p>Billboard</p>')
        ->and($kept[0]['code'])->toBe('billboard');
});

it('skips and records a contributor that throws or runs over its budget, and asks the rest', function (): void {
    $faults = new class implements ListenerFaults
    {
        /** @var list<array{string, string, Throwable}> */
        public array $recorded = [];

        public function record(string $owner, string $id, string $hook, Throwable $failure): void
        {
            $this->recorded[] = [$owner, $id, $failure];
        }
    };
    app()->instance(ListenerFaults::class, $faults);
    $clock = new class implements ColumnClock
    {
        public int $now = 0;

        public function nanoseconds(): int
        {
            return $this->now;
        }
    };
    app()->instance(ColumnClock::class, $clock);
    Billboard::$throws = true;

    $insertions = piStart(new ViewIntent('latest', isFront: true));
    $slow = piContributor([new PageInsertion('slow', PagePosition::bodyStart(), '<p>Slow</p>')], function () use ($clock): void {
        $clock->now += (PageInsertions::CALL_BUDGET_MS + 1) * 1_000_000;
    });
    $quick = piContributor([new PageInsertion('quick', PagePosition::bodyStart(), '<p>Quick</p>')]);
    $insertions->bind($slow, ['pages.insert'], 'tests/slow');
    $insertions->bind($quick, ['pages.insert'], 'tests/quick');

    expect(array_map(fn (array $entry): string => $entry['insertion']->key, $insertions->at(PagePosition::BODY_START)))->toBe(['quick'])
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->recorded))->toBe(['tests/billboard#page-insertions', 'tests/slow#page-insertions'])
        ->and($faults->recorded[0][2]->getMessage())->toBe('The billboard fell over.')
        ->and($faults->recorded[1][2])->toBeInstanceOf(ContributorTooSlow::class);
});

it('stops asking once the page\'s budget is spent, and records those it skipped', function (): void {
    $faults = new class implements ListenerFaults
    {
        /** @var list<string> */
        public array $ids = [];

        public function record(string $owner, string $id, string $hook, Throwable $failure): void
        {
            $this->ids[] = $id;
        }
    };
    app()->instance(ListenerFaults::class, $faults);
    $clock = new class implements ColumnClock
    {
        public int $now = 0;

        public function nanoseconds(): int
        {
            return $this->now;
        }
    };
    app()->instance(ColumnClock::class, $clock);
    Billboard::$gives = [];

    $insertions = piStart(new ViewIntent('latest', isFront: true));
    // Each takes its whole budget, never over it; three of them spend the page's.
    $spend = function () use ($clock): void {
        $clock->now += PageInsertions::CALL_BUDGET_MS * 1_000_000;
    };

    foreach (['one', 'two', 'three'] as $key) {
        $insertions->bind(piContributor([new PageInsertion($key, PagePosition::bodyEnd(), "<p>{$key}</p>")], $spend), ['pages.insert'], "tests/{$key}");
    }

    $late = piContributor([new PageInsertion('four', PagePosition::bodyEnd(), '<p>four</p>')]);
    $insertions->bind($late, ['pages.insert'], 'tests/four');

    expect(array_map(fn (array $entry): string => $entry['insertion']->key, $insertions->at(PagePosition::BODY_END)))->toBe(['one', 'two', 'three'])
        ->and($late->asked)->toBe(0)
        ->and($faults->ids)->toBe(['tests/four#page-insertions']);
});

it('asks nothing on a page whose template is an error page', function (): void {
    $insertions = piStart(new ViewIntent('latest', isFront: true));
    app(ComposerRegistry::class)->startPage(new ViewIntent('error', code: 500));

    expect($insertions->at(PagePosition::BODY_START))->toBe([])
        ->and(Billboard::$asked)->toBe([]);
});

it('refuses a position a page doesn\'t have', function (Closure $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'item 0' => [fn () => PagePosition::afterItem(0)],
    'item 101' => [fn () => PagePosition::afterItem(101)],
    'a step of 0' => [fn () => PagePosition::everyItems(0)],
    'a region slug with a space' => [fn () => PagePosition::region('after content')],
    'beside a region' => [fn () => PagePosition::region('after-content', 'inside')],
    'the middle' => [fn () => PagePosition::fixed('middle')],
    'a rail 200 wide' => [fn () => PagePosition::fixed('left', 200)],
    'a key with capitals' => [fn () => new PageInsertion('Ad', PagePosition::head(), '')],
]);

it('holds every few items to at most five, and a rail to its first width when none is given', function (): void {
    expect(PagePosition::everyItems(2, 9)->args['max'])->toBe(PagePosition::EVERY_ITEMS_MAX)
        ->and(PagePosition::fixed('left')->args['width'])->toBe(160)
        ->and(PagePosition::fixed('bottom', 300)->args['width'])->toBe(0);
});
