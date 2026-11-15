<?php

declare(strict_types=1);

use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $about = addrPublished($this->editor, 'About', 'page');
    $this->menu = navCreate($this->editor, 'Main');
    navSave($this->editor, $this->menu, [
        ['kind' => 'home'],
        ['kind' => 'content', 'target_id' => $about->id, 'children' => [['kind' => 'url', 'url' => 'https://example.org/', 'label' => 'Partners']]],
        ['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'url', 'url' => 'https://harbour.example/', 'label' => 'Harbour']]],
    ]);

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->editor)->get('/admin/navigation')->assertOk();
    $this->actingAs($this->editor)->get("/admin/navigation/{$this->menu->id}")->assertOk();
});

it('passes axe on Navigation and on a menu\'s editor, every target 24 pixels or more, in both schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)
        ->navigate('/admin/navigation')
        ->assertPresent('[aria-label="Actions for Main"]')
        ->assertNoAccessibilityIssues(3);

    $list = cmSmallTargets($page);

    $page->navigate("/admin/navigation/{$this->menu->id}")
        ->assertSee('https://example.org/')
        ->assertSeeIn('[data-test="crumbs"] a', 'Navigation')
        ->assertNoAccessibilityIssues(3);

    $editor = cmSmallTargets($page);

    expect($list['small'])->toBe([])
        ->and($list['checked'])->toBeGreaterThan(0)
        ->and($editor['small'])->toBe([])
        ->and($editor['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('creates a menu with Create menu, and opens its editor under the Navigation trail', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/navigation')
        ->type('#new-menu', 'Footer links')
        ->press('Create menu')
        ->assertSee('Menu created. Add its items, then choose where it shows.')
        ->assertPathBeginsWith('/admin/navigation/')
        ->assertScript("document.querySelector('h1')?.textContent", 'Footer links')
        ->assertSeeIn('[data-test="crumbs"] a', 'Navigation')
        ->assertDontSee('← Navigation');

    expect(Navigation::query()->where('name', 'Footer links')->exists())->toBeTrue();
});

it('saves a changed label with Save menu, and names the menu in the toast', function (): void {
    cmSignIn($this->editor)
        ->navigate("/admin/navigation/{$this->menu->id}")
        ->type('[aria-label="Label for Home"]', 'Start here')
        ->assertSee('You have unsaved changes')
        ->press('Save menu')
        ->assertSee('Saved “Main”.')
        ->assertDontSee('You have unsaved changes');

    expect(NavigationItem::query()->where('label', 'Start here')->exists())->toBeTrue();
});

it('adds the home page from the Add an item panel, labelled Home until renamed', function (): void {
    $empty = navCreate($this->editor, 'Footer');

    $page = cmSignIn($this->editor)
        ->navigate("/admin/navigation/{$empty->id}")
        ->press('Add an item')
        ->assertScript("(() => { const sheet = document.querySelector('[data-slot=\"sheet-content\"]'); return sheet === null ? -1 : sheet.getAnimations().filter((animation) => animation.playState === 'running').length; })()", 0)
        ->click('#add-item-tab-home')
        ->click('text="Add the home page"');

    cmWaitUntil($page, 'document.querySelector(\'[aria-label="Label for Home"]\') !== null');

    $page->click('[data-slot="sheet-content"] >> text="Close"');

    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"]\') === null');

    $page->press('Save menu')->assertSee('Saved “Footer”.');

    expect(NavigationItem::query()->where('navigation_id', $empty->id)->sole()->kind)->toBe('home');
});

it('shows the menu in a place chosen under Show in, naming the theme, and says what the place now shows', function (): void {
    cmSignIn($this->editor)
        ->navigate("/admin/navigation/{$this->menu->id}")
        ->assertSee('Where Fixture Good shows a menu. Each place shows one menu.')
        ->click('#slot-primary')
        ->click('[role="option"] >> text="Main (this menu)"')
        ->assertNotPresent('[role="listbox"]')
        ->assertSee('Saved. Header shows “Main”.');
});

it('deletes a menu from its row\'s Actions menu, after a confirmation that names it', function (): void {
    cmSignIn($this->editor)
        ->navigate('/admin/navigation')
        ->click('[aria-label="Actions for Main"] >> text="Delete…"')
        ->assertSee('Delete the menu “Main”?')
        // The menu stays open behind the confirmation; click inside the dialog once its own parts have settled.
        ->assertScript("(() => { const parts = [...document.querySelectorAll('[data-slot=\"dialog-content\"], [data-slot=\"dialog-overlay\"]')]; return parts.length === 0 ? -1 : parts.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()", 0)
        ->click('[role="dialog"] >> text="Delete menu"')
        ->assertSee('Deleted “Main”.')
        ->assertSee('No menus yet');

    expect(Navigation::query()->count())->toBe(0);
});

it('adds a heading from the Add an item panel, puts an item under it, and saves it', function (): void {
    $empty = navCreate($this->editor, 'Footer');

    $page = cmSignIn($this->editor)
        ->navigate("/admin/navigation/{$empty->id}")
        ->press('Add an item');

    cmWaitUntil($page, "(() => { const sheet = document.querySelector('[data-slot=\"sheet-content\"]'); return sheet !== null && sheet.getAnimations().every((animation) => animation.playState !== 'running'); })()");

    $page->click('#add-item-tab-heading')
        ->type('#add-item-heading', 'Visit')
        ->press('Add heading');

    cmWaitUntil($page, "document.body.textContent.includes('Needs an item under it')");

    $page->click('#add-item-tab-home')
        ->click('text="Add the home page"');

    cmWaitUntil($page, 'document.querySelector(\'[aria-label="Label for Home"]\') !== null');

    $page->click('[data-slot="sheet-content"] >> text="Close"');

    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"]\') === null');

    $page->click('[aria-label="Move under the item above: Home"]');

    cmWaitUntil($page, "!document.body.textContent.includes('Needs an item under it')");

    $page->press('Save menu');

    cmWaitUntil($page, "document.body.textContent.includes('Saved “Footer”.')");

    $heading = NavigationItem::query()->where('navigation_id', $empty->id)->where('kind', 'heading')->sole();

    expect($heading->label)->toBe('Visit')
        ->and(NavigationItem::query()->where('parent_id', $heading->id)->sole()->kind)->toBe('home');
});

it('refuses to indent a heading to the third level, saying why', function (): void {
    $page = cmSignIn($this->editor)
        ->navigate("/admin/navigation/{$this->menu->id}")
        ->press('Add item');

    cmWaitUntil($page, "(() => { const sheet = document.querySelector('[data-slot=\"sheet-content\"]'); return sheet !== null && sheet.getAnimations().every((animation) => animation.playState !== 'running'); })()");

    $page->click('#add-item-tab-heading')
        ->type('#add-item-heading', 'Club')
        ->press('Add heading');

    cmWaitUntil($page, 'document.querySelector(\'[aria-label="Move under the item above: Club"]\') !== null');

    $page->click('[data-slot="sheet-content"] >> text="Close"');

    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"]\') === null');

    // Under Visit, after Harbour: one more Indent would put it at the third level.
    $page->click('[aria-label="Move under the item above: Club"]');

    cmWaitUntil($page, <<<'JS'
        document.querySelector('[aria-label="Move under the item above: Club"]')?.disabled === true
            && document.querySelector('[aria-label="Move under the item above: Club"]').title === 'A heading can’t go to the third level, where nothing can go under it.'
        JS);
});

it('keeps each item on one line at 375 pixels, its moves in the item\'s menu', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), "/admin/navigation/{$this->menu->id}");

    cmWaitUntil($page, "document.querySelectorAll('[data-item-key]').length > 0");

    $sizes = $page->script(<<<'JS'
        () => {
            const rows = [...document.querySelectorAll('[data-item-key]')];
            const visible = (element) => element !== null && element.getBoundingClientRect().width > 0;

            return {
                page: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
                rows: rows.length,
                apart: rows.filter((row) => {
                    const label = row.querySelector('input[data-control="label"]');
                    const more = row.querySelector('[data-control="more"]');

                    return label === null || more === null
                        || Math.abs(label.getBoundingClientRect().top - more.getBoundingClientRect().top) >= 2;
                }).length,
                moves: [...document.querySelectorAll('[aria-label^="Move up:"]')].filter(visible).length,
            };
        }
        JS);

    $targets = cmSmallTargets($page);

    expect($sizes['page'])->toBe(0)
        ->and($sizes['rows'])->toBeGreaterThan(0)
        ->and($sizes['apart'])->toBe(0)
        ->and($sizes['moves'])->toBe(0)
        ->and($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
});

it('moves an item down from its menu on a phone, focus back on its menu button', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), "/admin/navigation/{$this->menu->id}");

    cmWaitUntil($page, 'document.querySelector(\'[aria-label="More actions for Home"]\') !== null');

    $labels = "[...document.querySelectorAll('input[data-control=\"label\"]')].map((input) => input.value || input.placeholder).join('|')";
    $before = $page->script("() => {$labels}");

    $page->click('[aria-label="More actions for Home"]');

    shellSettled($page, '[data-slot="dropdown-menu-content"]');

    $page->click('[data-slot="dropdown-menu-content"] >> text="Move down"');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"dropdown-menu-content\"]') === null");
    cmWaitUntil($page, "({$labels}) !== ".json_encode($before, JSON_THROW_ON_ERROR));
    cmWaitUntil($page, 'document.activeElement?.getAttribute("aria-label") === "More actions for Home" && document.activeElement.dataset.control === "more"');

    $page->assertVisible('[data-test="save-bar"]');
});

it('says in the menu why a move can\'t be made', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), "/admin/navigation/{$this->menu->id}");

    $page->press('Add item');

    cmWaitUntil($page, "(() => { const sheet = document.querySelector('[data-slot=\"sheet-content\"]'); return sheet !== null && sheet.getAnimations().every((animation) => animation.playState !== 'running'); })()");

    $page->click('#add-item-tab-heading')
        ->type('#add-item-heading', 'Club')
        ->press('Add heading');

    cmWaitUntil($page, 'document.querySelector(\'[aria-label="More actions for Club"]\') !== null');

    $page->click('[data-slot="sheet-content"] >> text="Close"');

    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"]\') === null');

    // Under Visit, after Harbour, as the wide screen's case does with its button.
    $page->click('[aria-label="More actions for Club"]');

    shellSettled($page, '[data-slot="dropdown-menu-content"]');

    $page->click('[data-slot="dropdown-menu-content"] >> text="Move under the item above"');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"dropdown-menu-content\"]') === null");

    $page->click('[aria-label="More actions for Club"]');

    shellSettled($page, '[data-slot="dropdown-menu-content"]');

    cmWaitUntil($page, <<<'JS'
        (() => {
            const item = [...document.querySelectorAll('[data-slot="dropdown-menu-content"] [role="menuitem"]')]
                .find((element) => element.textContent.startsWith('Move under the item above'));

            return item !== undefined
                && item.getAttribute('aria-disabled') === 'true'
                && item.textContent.includes('A heading can’t go to the third level, where nothing can go under it.');
        })()
        JS);
});

it('puts focus on the next item\'s menu button after Remove from an item\'s menu on a phone', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), "/admin/navigation/{$this->menu->id}");

    cmWaitUntil($page, 'document.querySelector(\'[aria-label="More actions for Home"]\') !== null');

    $page->click('[aria-label="More actions for Home"]');

    shellSettled($page, '[data-slot="dropdown-menu-content"]');

    $page->click('[data-slot="dropdown-menu-content"] >> text="Remove"');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"dropdown-menu-content\"]') === null && document.querySelector('[aria-label=\"More actions for Home\"]') === null");
    cmWaitUntil($page, 'document.activeElement?.getAttribute("aria-label") === "More actions for About"');
});

it('shows a French menu in a place for French readers, and adds the language switcher, both kept on reload', function (): void {
    siteSetLanguage(User::factory()->create()->assignRole('administrator'), 'en-GB');
    Languages::set(['fr']);
    $principal = app(CreateNavigation::class)->execute(new CreateNavigationInput('Principal', (string) $this->editor->id, 'fr'));
    $this->actingAs($this->editor)->get("/admin/navigation/{$principal->id}")->assertOk();

    $page = cmNavigate(cmSignIn($this->editor), "/admin/navigation/{$principal->id}");
    cmWaitUntil($page, "document.getElementById('menu-readers-of') !== null");
    $page->assertScript("document.getElementById('menu-readers-of').textContent.includes('French')")
        ->click('#slot-primary')
        ->click('[role="option"] >> text="Principal (this menu)"');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null && document.body.innerText.includes('Saved. Header shows “Principal”.')");

    expect(SettingsGroupRecord::query()->find('theme.good')->translations)->toEqual(['fr' => ['nav_primary' => $principal->id]])
        ->and(app(SettingsReader::class)->string('theme.good', 'nav_primary'))->not->toBe($principal->id);

    $page->press('Add an item');
    shellSettled($page, '[data-slot="sheet-content"]');
    $page->click('#add-item-tab-languages')->click('[data-test="add-language-switcher"]');
    $page->click('[data-slot="sheet-content"] >> text="Close"');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="sheet-content"]\') === null');
    $page->press('Save menu');
    cmWaitUntil($page, "document.body.innerText.includes('Saved “Principal”.')");

    expect(NavigationItem::query()->where('navigation_id', $principal->id)->pluck('kind')->all())->toBe(['languages']);

    cmNavigate($page, "/admin/navigation/{$principal->id}");
    cmWaitUntil($page, "document.getElementById('slot-primary')?.textContent.includes('Principal') === true");
    $page->assertScript("document.getElementById('menu-readers-of').textContent.includes('French')");
    cmNoJavaScriptErrors($page);
});
