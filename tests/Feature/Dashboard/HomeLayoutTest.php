<?php

declare(strict_types=1);

use App\Domain\Dashboard\DashboardPanel;
use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Dashboard\Queries\Dashboard;
use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Types\ContentType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Chidi Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Ngozi Editor'])->assignRole('editor');
    $this->contributor = User::factory()->create(['name' => 'Tamuno Contributor'])->assignRole('contributor');
});

afterEach(fn () => PluginFixtures::forget());

it('sends every panel the person may see in the catalogue, empty ones too, with its declared size and kind', function (): void {
    $page = app(Dashboard::class)->forUser($this->author);

    expect(array_column($page['catalogue'], 'key'))->toBe([
        'site_status',
        'returned_to_me',
        'my_items_in_review',
        'my_drafts',
        'scheduled',
        'my_recent_edits',
        'quick_draft',
    ])
        ->and(collect($page['catalogue'])->firstWhere('key', 'quick_draft'))->toMatchArray([
            'kind' => 'quick-draft',
            'size' => 'small',
            'owner' => 'core',
            'ownerName' => null,
        ])
        ->and(collect($page['catalogue'])->firstWhere('key', 'site_status'))->toMatchArray([
            'kind' => 'site-status',
            'size' => 'medium',
            'owner' => 'core',
            'ownerName' => null,
        ])
        ->and(collect($page['catalogue'])->firstWhere('key', 'my_drafts')['size'])->toBe('medium')
        ->and(collect($page['catalogue'])->firstWhere('key', 'scheduled')['size'])->toBe('small')
        ->and($page['panels'])->toBe([]);
});

it('sends the panels in the person\'s order and leaves hidden ones out', function (): void {
    lifecycleDraft($this->author, 'Night market');

    lscrPrefer($this->author, ['dashboard.order' => ['my_recent_edits', 'my_drafts']]);

    expect(array_column(app(Dashboard::class)->forUser($this->author)['panels'], 'key'))
        ->toBe(['my_recent_edits', 'my_drafts']);

    lscrPrefer($this->author, ['dashboard.hidden' => ['my_drafts']]);

    expect(array_column(app(Dashboard::class)->forUser($this->author)['panels'], 'key'))
        ->toBe(['my_recent_edits']);
});

it('doesn\'t run a hidden panel\'s query', function (): void {
    lifecycleDraft($this->author, 'Night market');

    $dashboard = fn (): array => app(Dashboard::class)->forUser($this->author);
    $dashboard();

    $shown = count(dashQueries($dashboard));

    lscrPrefer($this->author, ['dashboard.hidden' => ['my_drafts']]);

    expect(count(dashQueries($dashboard)))->toBeLessThan($shown);
});

it('puts a panel the order doesn\'t name at the end, in registry order', function (): void {
    lscrPrefer($this->author, ['dashboard.order' => ['my_drafts']]);

    $order = app(Dashboard::class)->forUser($this->author)['layout']['order'];

    expect($order[0])->toBe('my_drafts')
        ->and($order)->toEqualCanonicalizing(array_column(app(Dashboard::class)->forUser($this->author)['catalogue'], 'key'))
        ->and(array_unique($order))->toHaveCount(count($order));
});

it('reads each panel\'s size, the person\'s over the declared one', function (): void {
    lscrPrefer($this->author, ['dashboard.sizes' => ['my_drafts:full']]);

    $sizes = app(Dashboard::class)->forUser($this->author)['layout']['sizes'];

    expect($sizes['my_drafts'])->toBe('full')
        ->and($sizes['scheduled'])->toBe('small');
});

it('keeps the rest of Home when one panel\'s query fails', function (): void {
    lifecycleDraft($this->author, 'Night market');

    app(DashboardRegistry::class)->register(new DashboardPanel(
        'broken_panel',
        'Broken panel',
        50,
        HomeLayoutBrokenQuery::class,
        ['create'],
        size: 'small',
        kind: 'rows',
    ));

    $page = app(Dashboard::class)->forUser($this->author);
    $broken = collect($page['panels'])->firstWhere('key', 'broken_panel');

    expect($page)->not->toBeEmpty()
        ->and($broken)->toMatchArray(['failed' => true, 'rows' => []])
        ->and(array_column($page['panels'], 'key'))->toContain('my_drafts', 'my_recent_edits', 'broken_panel');

    $this->actingAs($this->author)->get('/admin')->assertOk();
});

it('gives a plugin\'s panel Small when it declares no size', function (): void {
    app(DashboardRegistry::class)->register(new DashboardPanel(
        'plugin_visits',
        'Visits',
        700,
        HomeLayoutEmptyQuery::class,
        ['create'],
        owner: 'tests/hello',
        kind: 'rows',
    ));

    $entry = collect(app(Dashboard::class)->forUser($this->author)['catalogue'])->firstWhere('key', 'plugin_visits');

    expect($entry['size'])->toBe('small');
});

it('names a plugin\'s panel\'s plugin and keeps it out of core\'s section', function (): void {
    PluginFixtures::installed(['tests/hello' => 'hello']);

    app(DashboardRegistry::class)->register(new DashboardPanel(
        'plugin_visits',
        'Visits',
        700,
        HomeLayoutEmptyQuery::class,
        ['create'],
        owner: 'tests/hello',
        size: 'small',
        kind: 'rows',
    ));

    $entry = collect(app(Dashboard::class)->forUser($this->author)['catalogue'])->firstWhere('key', 'plugin_visits');

    expect($entry['owner'])->toBe('tests/hello')
        ->and($entry['ownerName'])->toBe('Hello');
});

it('refuses a panel with a size or kind it doesn\'t know, or a rows panel with no query', function (): void {
    $registry = app(DashboardRegistry::class);

    expect(fn () => $registry->register(new DashboardPanel('bad_size', 'Bad', 900, HomeLayoutEmptyQuery::class, size: 'huge')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new DashboardPanel('bad_kind', 'Bad', 901, HomeLayoutEmptyQuery::class, kind: 'chart')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register(new DashboardPanel('no_query', 'Bad', 902, null, kind: 'rows')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses an arrangement naming a panel that doesn\'t exist, or a size that isn\'t one', function (): void {
    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(
        ['dashboard.order' => ['nope']],
        (string) $this->author->id,
    )))->toThrow(ValidationException::class);

    expect($this->author->refresh()->preferences)->toBe([]);

    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(
        ['dashboard.sizes' => ['my_drafts:huge']],
        (string) $this->author->id,
    )))->toThrow(ValidationException::class);

    expect($this->author->refresh()->preferences)->toBe([]);
});

it('says whether the person may upload', function (): void {
    expect(app(Dashboard::class)->forUser($this->author)['canUpload'])->toBeTrue()
        ->and(app(Dashboard::class)->forUser($this->contributor)['canUpload'])->toBeFalse();
});

/**
 * A panel query that always throws, so Home can prove a failed panel stays a panel.
 */
final class HomeLayoutBrokenQuery implements DashboardPanelQuery
{
    public function rows(User $viewer, array $types, int $limit): array
    {
        throw new RuntimeException('The panel query failed on purpose.');
    }
}

/**
 * A panel query that returns no rows, for registering an empty plugin panel in tests.
 *
 * @param  list<ContentType>  $types
 */
final class HomeLayoutEmptyQuery implements DashboardPanelQuery
{
    public function rows(User $viewer, array $types, int $limit): array
    {
        return [];
    }
}
