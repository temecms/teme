<?php

declare(strict_types=1);

use App\Domain\Dashboard\DashboardPanel;
use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Types\ContentType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Ngozi Editor'])->assignRole('editor');
    $this->contributor = User::factory()->create(['name' => 'Tamuno Contributor'])->assignRole('contributor');

    lifecycleDraft($this->editor, 'Night market');
    schedSubmit(lifecycleDraft($this->contributor, 'Harbour festival'), $this->contributor);
    schedFor(lifecycleDraft($this->editor, 'Ferry fares'), $this->editor, now()->addDay()->toIso8601String());

    $this->actingAs($this->editor)->get(route('dashboard'))->assertOk();
});

it('greets the person and offers New post, Upload media and Customize', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-slot="page-header"]');

    $page->assertSee('Good ')
        ->assertSee('Ngozi')
        ->assertSee('New Post')
        ->assertSee('Upload media')
        ->assertSee('Customize');

    $contributor = cmSignIn($this->contributor)->resize(1280, 900);
    shellSettled($contributor, '[data-slot="page-header"]');
    $contributor->assertDontSee('Upload media');
});

it('draws the panels in the person\'s sizes in a 12-column grid', function (): void {
    lscrPrefer($this->editor, ['dashboard.sizes' => ['my_drafts:full']]);

    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-grid"]');

    $widths = $page->script(<<<'JS'
        () => {
            const grid = document.querySelector('[data-test="home-grid"]');
            const drafts = document.querySelector('[data-panel="my_drafts"]');
            const scheduled = document.querySelector('[data-panel="scheduled"]');
            if (!grid || !drafts || !scheduled) {
                return null;
            }

            return {
                grid: grid.getBoundingClientRect().width,
                drafts: drafts.getBoundingClientRect().width,
                scheduled: scheduled.getBoundingClientRect().width,
            };
        }
        JS);

    // Small is 4 of 12; with gap-4 (16px) three fit across, so one is (grid − 2×16) / 3.
    expect($widths)->not->toBeNull()
        ->and(abs($widths['drafts'] - $widths['grid']))->toBeLessThan(2)
        ->and(abs($widths['scheduled'] - (($widths['grid'] - 32) / 3)))->toBeLessThan(2);
});

it('shows the figures as links to their lists', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="site-figures"]');

    $page->assertScript(<<<'JS'
        () => {
            const links = [...document.querySelectorAll('[data-test="site-figures"] a')];
            return links.length > 0 && links.every((link) => /\d/.test(link.textContent ?? ''));
        }
        JS);
});

it('draws Your work and leaves out From plugins when no plugin has a panel', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-your-work"]');

    $page->assertSee('Your work')
        ->assertDontSee('From plugins');
});

it('draws From plugins with the plugin\'s mark when one has', function (): void {
    app(DashboardRegistry::class)->register(new DashboardPanel(
        'plugin_visits',
        'Visits',
        700,
        HomeScreenEmptyQuery::class,
        ['create'],
        owner: 'tests/hello',
        size: 'small',
        kind: 'rows',
    ));

    // An empty plugin panel shows only while customizing; seed a row by failing so it draws.
    app(DashboardRegistry::class)->register(new DashboardPanel(
        'plugin_broken',
        'Broken visits',
        710,
        HomeScreenBrokenQuery::class,
        ['create'],
        owner: 'tests/hello',
        size: 'small',
        kind: 'rows',
    ));

    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-from-plugins"]');

    $page->assertSee('From plugins')
        ->assertSee('Broken visits')
        ->assertPresent('[data-test="home-from-plugins"] [data-slot="plugin-mark"]');
});

it('shows a failed panel\'s own error and Try again, with the rest of Home drawn', function (): void {
    app(DashboardRegistry::class)->register(new DashboardPanel(
        'broken_panel',
        'Broken panel',
        50,
        HomeScreenBrokenQuery::class,
        ['create'],
        size: 'small',
        kind: 'rows',
    ));

    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-panel="broken_panel"]');

    $page->assertSee('This panel couldn\'t load. The rest of Home is unaffected.')
        ->assertSee('Try again')
        ->assertSee('Your drafts')
        ->assertSee('Scheduled');
});

it('says Nothing needs you right now to a person with nothing waiting', function (): void {
    $author = User::factory()->create(['name' => 'Chidi Author'])->assignRole('author');
    $this->actingAs($author)->get(route('dashboard'))->assertOk();

    $page = cmSignIn($author)->resize(1280, 900);
    shellSettled($page, '[data-test="home-your-work"]');

    $page->assertSee('Nothing needs you right now.');
});

it('passes axe in both schemes at 1280 and 375, with no target under 24px', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    foreach ([1280, 375] as $width) {
        $page = cmSignIn($this->editor)->resize($width, 900);
        shellSettled($page, '[data-slot="page-header"]');
        $page->assertNoAccessibilityIssues(3);
        $targets = cmSmallTargets($page);
        expect($targets['small'])->toBe([])
            ->and($targets['checked'])->toBeGreaterThan(0);
    }
})->with(['light', 'dark']);

it('is one column on a phone', function (): void {
    $page = cmSignIn($this->editor)->resize(375, 900);
    shellSettled($page, '[data-test="home-grid"]');

    $page->assertScript(<<<'JS'
        () => {
            const grid = document.querySelector('[data-test="home-grid"]');
            const panels = [...document.querySelectorAll('[data-test="home-grid"] [data-panel]')];
            if (!grid || panels.length === 0) {
                return false;
            }
            const width = grid.getBoundingClientRect().width;
            return panels.every((panel) => Math.abs(panel.getBoundingClientRect().width - width) < 2);
        }
        JS);
});

/**
 * A panel query that always throws, for Home's failed-panel browser cases.
 */
final class HomeScreenBrokenQuery implements DashboardPanelQuery
{
    public function rows(User $viewer, array $types, int $limit): array
    {
        throw new RuntimeException('The panel query failed on purpose.');
    }
}

/**
 * A panel query that returns no rows.
 *
 * @param  list<ContentType>  $types
 */
final class HomeScreenEmptyQuery implements DashboardPanelQuery
{
    public function rows(User $viewer, array $types, int $limit): array
    {
        return [];
    }
}
