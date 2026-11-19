<?php

declare(strict_types=1);

use App\Domain\Dashboard\DashboardPanel;
use App\Domain\Dashboard\DashboardPanelQuery;
use App\Domain\Dashboard\DashboardRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Types\ContentType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

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

it('shows one row of five figures, each with its status dot, and the date ending in a full stop', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="site-figures"]');

    $page->assertScript(<<<'JS'
        () => {
            const figures = [...document.querySelectorAll('[data-test="site-figures"] > *')];
            const dots = figures.map((figure) => figure.querySelector('[data-dot]')?.getAttribute('data-dot'));
            const top = new Set(figures.map((figure) => Math.round(figure.getBoundingClientRect().top)));
            return figures.length === 5 && top.size === 1 && dots.join() === 'ok,neutral-dot,info,warn,accent';
        }
        JS)
        ->assertScript('() => /[A-Za-z]+, [A-Za-z]+ \\d+\\. Your work first/.test(document.body.innerText)');
});

it('draws Your work and leaves out From plugins when no plugin has a panel', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-your-work"]');

    $page->assertSee('Your work')
        ->assertDontSee('From plugins');
});

it('hides From plugins outside Customize when every plugin panel is empty', function (): void {
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

    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-your-work"]');
    $page->assertDontSee('From plugins');

    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-test="home-from-plugins"]');
    $page->assertSee('From plugins')
        ->assertPresent('[data-panel="plugin_visits"]');
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

it('opens Customize, shows every panel with its controls, empty ones too, and leaves with Done', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-customize"]');

    $page->assertMissing('[data-panel="returned_to_me"]')
        ->click('[data-test="home-customize"]');
    shellSettled($page, '[data-test="home-customize-bar"]');
    $page->assertPresent('[data-panel="returned_to_me"] [data-control="move-earlier"]')
        ->click('[data-test="home-customize-done"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="home-customize-bar"]\') === null');
    $page->assertScript('document.activeElement?.getAttribute("data-test") === "home-customize"');
});

it('moves a panel later from the keyboard, keeps focus on the button, announces it, and saves it', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-panel="my_drafts"]');

    $before = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel'))
        JS);
    $from = array_search('my_drafts', $before, true);

    $page->assertScript(<<<'JS'
        () => {
            const button = document.querySelector('[data-panel="my_drafts"] [data-control="move-later"]');
            button?.focus();

            return document.activeElement === button;
        }
        JS);
    $page->keys(':focus', 'Enter');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="home-announce"]')?.textContent?.includes('Your drafts moved to position') === true
        JS);

    $after = $page->script(<<<'JS'
        () => ({
            order: [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel')),
            focus: document.activeElement?.getAttribute('aria-label') ?? '',
            announce: document.querySelector('[data-test="home-announce"]')?.textContent ?? '',
        })
        JS);
    $to = array_search('my_drafts', $after['order'], true);

    expect($after['focus'])->toContain('Move Your drafts later')
        ->and($after['announce'])->toContain('Your drafts moved to position')
        ->and($from)->not->toBeFalse()
        ->and($to)->toBe($from + 1);

    $this->editor->refresh();
    $saved = PreferencesFor::user($this->editor)['dashboard.order'];
    $shownSaved = array_values(array_filter(
        $saved,
        fn (string $key): bool => in_array($key, $after['order'], true),
    ));
    expect($shownSaved)->toBe($after['order']);

    $page->click('[data-test="home-customize-done"]');
    cmNavigate($page, '/admin');
    shellSettled($page, '[data-test="home-grid"]');
    $reloaded = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel'))
        JS);
    expect(array_search('my_drafts', $reloaded, true))->toBe(
        array_search('my_drafts', array_values(array_filter(
            $after['order'],
            fn (string $key): bool => in_array($key, $reloaded, true),
        )), true),
    );
});

it('keeps focus on Move earlier at the first place', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-test="home-grid"]');

    $page->assertScript(<<<'JS'
        () => {
            const first = document.querySelector('[data-test="home-your-work"] [data-panel]');
            const button = first?.querySelector('[data-control="move-earlier"]');
            button?.focus();
            button?.click();
            return document.activeElement === button
                && button?.getAttribute('aria-disabled') === 'true'
                && first?.getAttribute('data-panel') === document.querySelector('[data-test="home-your-work"] [data-panel]')?.getAttribute('data-panel');
        }
        JS);
});

it('resizes a panel and saves only a size that differs', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-panel="scheduled"]');

    $page->click('[data-panel="scheduled"] [data-control="size"] [aria-label="Full width"]');
    cmWaitUntil($page, <<<'JS'
        Math.abs(document.querySelector('[data-panel="scheduled"]').getBoundingClientRect().width
            - document.querySelector('[data-test="home-grid"]').getBoundingClientRect().width) < 2
        JS);

    $this->editor->refresh();
    expect(PreferencesFor::user($this->editor)['dashboard.sizes'])->toBe(['scheduled:full']);

    $page->click('[data-panel="scheduled"] [data-control="size"] [aria-label="Small"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="home-announce"]\')?.textContent?.includes("Scheduled is now") === true');
    $this->editor->refresh();
    expect(PreferencesFor::user($this->editor)['dashboard.sizes'])->toBe([]);
});

it('hides a panel with Undo, and adds it back from Add a panel', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-panel="my_drafts"]');

    $page->click('[data-panel="my_drafts"] [data-control="hide"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="flash-toast"]\') !== null');
    $page->assertMissing('[data-panel="my_drafts"]')
        ->click('[data-test="flash-toast"] button:not([data-test="flash-toast-dismiss"])');
    cmWaitUntil($page, 'document.querySelector(\'[data-panel="my_drafts"]\') !== null');

    $page->click('[data-panel="my_drafts"] [data-control="hide"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-panel="my_drafts"]\') === null');
    $page->click('[data-test="home-customize-bar"] >> text="Add a panel"');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') !== null');
    $page->assertSee('Teme')
        ->click('[role="menuitem"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-panel="my_drafts"]\') !== null');
});

it('resets to the site default', function (): void {
    lscrPrefer($this->editor, [
        'dashboard.order' => ['my_recent_edits', 'my_drafts', 'scheduled'],
        'dashboard.hidden' => ['scheduled'],
        'dashboard.sizes' => ['my_drafts:full'],
    ]);

    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-test="home-customize-bar"]');
    $page->click('[data-test="home-customize-reset"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="flash-toast"]\') !== null');

    $this->editor->refresh();
    expect(PreferencesFor::user($this->editor)['dashboard.order'])->toBe([])
        ->and(PreferencesFor::user($this->editor)['dashboard.hidden'])->toBe([])
        ->and(PreferencesFor::user($this->editor)['dashboard.sizes'])->toBe([]);
});

it('puts Home back from the server and says so when a save fails', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-panel="my_drafts"]');

    $page->assertAttribute(
        '[data-panel="my_drafts"] [data-control="hide"]',
        'aria-label',
        'Hide Your drafts',
    );

    // Preference the page has not loaded: reload must pick this up, not the
    // pre-move client layout.
    lscrPrefer($this->editor, [
        'dashboard.order' => ['my_recent_edits', 'my_drafts', 'scheduled', 'quick_draft', 'waiting_for_my_review', 'returned_to_me', 'my_items_in_review'],
    ]);

    Route::patch('admin/account/preferences', fn () => response()->json(['message' => 'Server Error'], 500));

    $page->assertScript(<<<'JS'
        () => {
            const button = document.querySelector('[data-panel="my_drafts"] [data-control="move-later"]');
            button?.focus();

            return document.activeElement === button;
        }
        JS);
    $page->keys(':focus', 'Enter');

    cmWaitUntil($page, <<<'JS'
        document.body.innerText.includes("Couldn't save your Home. Try again.") === true
        JS);

    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="home-your-work"] [data-panel]')?.getAttribute('data-panel') === 'my_recent_edits'
        JS);

    $order = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel'))
        JS);

    expect($order[0] ?? null)->toBe('my_recent_edits');
});

it('keeps Customize usable on a phone', function (): void {
    $page = cmSignIn($this->editor)->resize(375, 900);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-test="home-customize-bar"]');

    $page->assertPresent('[data-panel="my_drafts"] [data-control="move-earlier"]')
        ->assertPresent('[data-panel="my_drafts"] [data-control="move-later"]')
        ->assertPresent('[data-panel="my_drafts"] [data-control="hide"]')
        ->assertMissing('[data-panel="my_drafts"] [data-control="size"]');

    $sizes = $page->script(<<<'JS'
        () => {
            const buttons = ['move-earlier', 'move-later', 'hide'].map((control) => {
                const node = document.querySelector(`[data-panel="my_drafts"] [data-control="${control}"]`);
                const box = node?.getBoundingClientRect();
                return box ? Math.min(box.width, box.height) : 0;
            });
            return buttons;
        }
        JS);

    expect(min($sizes))->toBeGreaterThanOrEqual(44);
});

it('drags a panel by its grip to after another, and saves it', function (): void {
    // Tall enough that Your work stays on screen — Playwright's drag otherwise
    // scrolls the target into view and starts the drag on the wrong panel.
    $page = cmSignIn($this->editor)->resize(1280, 1800);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-panel="my_drafts"] [data-drag-handle]');

    $targetKey = $page->script(<<<'JS'
        () => {
            const keys = [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')]
                .map((node) => node.getAttribute('data-panel'));
            const from = keys.indexOf('my_drafts');

            return keys[from + 2] ?? null;
        }
        JS);

    expect($targetKey)->not->toBeNull();

    $points = $page->page()->evaluate(<<<'JS'
        (key) => {
            const panel = document.querySelector('[data-panel="my_drafts"]');
            const handle = document.querySelector('[data-panel="my_drafts"] [data-drag-handle]');
            const target = document.querySelector(`[data-panel="${key}"]`);
            if (!panel || !handle || !target) {
                return null;
            }

            const panelBox = panel.getBoundingClientRect();
            const handleBox = handle.getBoundingClientRect();
            const targetBox = target.getBoundingClientRect();

            return {
                source: {
                    x: handleBox.x + handleBox.width / 2 - panelBox.x,
                    y: handleBox.y + handleBox.height / 2 - panelBox.y,
                },
                target: {
                    x: targetBox.width * 0.75,
                    y: targetBox.height / 2,
                },
            };
        }
        JS, $targetKey);

    expect($points)->not->toBeNull();

    $page->page()
        ->locator('[data-panel="my_drafts"]')
        ->dragTo(
            $page->page()->locator('[data-panel="'.$targetKey.'"]'),
            [
                'sourcePosition' => [
                    'x' => (int) round($points['source']['x']),
                    'y' => (int) round($points['source']['y']),
                ],
                'targetPosition' => [
                    'x' => (int) round($points['target']['x']),
                    'y' => (int) round($points['target']['y']),
                ],
            ],
        );

    cmWaitUntil($page, <<<JS
        (() => {
            const keys = [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')]
                .map((node) => node.getAttribute('data-panel'));
            const drafts = keys.indexOf('my_drafts');
            const target = keys.indexOf('{$targetKey}');

            return drafts === target + 1;
        })()
        JS);

    cmWaitUntil($page, 'document.querySelector(\'[data-test="home-announce"]\')?.textContent?.includes("moved to position") === true');

    $this->editor->refresh();
    $saved = PreferencesFor::user($this->editor)['dashboard.order'];
    $shownSaved = array_values(array_filter(
        $saved,
        fn (string $key): bool => in_array($key, [
            'waiting_for_my_review',
            'returned_to_me',
            'my_items_in_review',
            'my_drafts',
            'scheduled',
            'my_recent_edits',
            'quick_draft',
        ], true),
    ));
    expect(array_search('my_drafts', $shownSaved, true))
        ->toBe(array_search($targetKey, $shownSaved, true) + 1);
});

it('never drops a core panel among plugins\' panels', function (): void {
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

    $page = cmSignIn($this->editor)->resize(1280, 1800);
    shellSettled($page, '[data-test="home-customize"]');
    $page->click('[data-test="home-customize"]');
    shellSettled($page, '[data-panel="plugin_visits"] [data-drag-handle]');

    $before = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel'))
        JS);

    $page->script(<<<'JS'
        () => {
            window.__temePluginDropEdges = [];
            window.__temeWatchPluginDrop = true;
            const tick = () => {
                if (!window.__temeWatchPluginDrop) {
                    return;
                }

                const edge = document
                    .querySelector('[data-panel="plugin_visits"]')
                    ?.getAttribute('data-drop-edge');
                if (edge) {
                    window.__temePluginDropEdges.push(edge);
                }

                requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);

            return true;
        }
        JS);

    $points = $page->page()->evaluate(<<<'JS'
        () => {
            const panel = document.querySelector('[data-panel="my_drafts"]');
            const handle = document.querySelector('[data-panel="my_drafts"] [data-drag-handle]');
            const target = document.querySelector('[data-panel="plugin_visits"]');
            if (!panel || !handle || !target) {
                return null;
            }

            const panelBox = panel.getBoundingClientRect();
            const handleBox = handle.getBoundingClientRect();
            const targetBox = target.getBoundingClientRect();

            return {
                source: {
                    x: handleBox.x + handleBox.width / 2 - panelBox.x,
                    y: handleBox.y + handleBox.height / 2 - panelBox.y,
                },
                target: {
                    x: targetBox.width / 2,
                    y: targetBox.height / 2,
                },
            };
        }
        JS);

    expect($points)->not->toBeNull();

    $page->page()
        ->locator('[data-panel="my_drafts"]')
        ->dragTo(
            $page->page()->locator('[data-panel="plugin_visits"]'),
            [
                'sourcePosition' => [
                    'x' => (int) round($points['source']['x']),
                    'y' => (int) round($points['source']['y']),
                ],
                'targetPosition' => [
                    'x' => (int) round($points['target']['x']),
                    'y' => (int) round($points['target']['y']),
                ],
            ],
        );

    $edges = $page->script('() => window.__temePluginDropEdges ?? []');
    $after = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel'))
        JS);

    expect($edges)->toBe([])
        ->and($after)->toBe($before);
    $this->editor->refresh();
    expect(PreferencesFor::user($this->editor)['dashboard.order'])->toBe([]);
});

it('doesn\'t drag outside Customize', function (): void {
    $page = cmSignIn($this->editor)->resize(1280, 900);
    shellSettled($page, '[data-test="home-grid"]');

    $page->assertMissing('[data-drag-handle]');

    $before = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel'))
        JS);

    $page->page()
        ->locator('[data-panel="my_drafts"] h3')
        ->dragTo($page->page()->locator('[data-panel="scheduled"]'));

    $after = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="home-your-work"] [data-panel]')].map((node) => node.getAttribute('data-panel'))
        JS);

    expect($after)->toBe($before);
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
