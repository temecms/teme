<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/_catalogue')->assertOk();
});

/**
 * The catalogue at its Combobox section, once the section has drawn.
 */
function cbCatalogue(User $admin, int $width = 1280): mixed
{
    $page = cmNavigate(cmSignIn($admin)->resize($width, 900), '/admin/_catalogue#combobox');

    return cmWaitUntil($page, "document.querySelector('#catalogue-combobox-error') !== null");
}

/**
 * Presses a key on whatever has focus, as a person at the keyboard does.
 */
function cbPress(mixed $page, string $key): mixed
{
    $page->page()->locator(':focus')->press($key);

    return $page;
}

/**
 * Types into whatever has focus, one key at a time.
 */
function cbType(mixed $page, string $text): mixed
{
    $page->page()->locator(':focus')->type($text);

    return $page;
}

/**
 * Opens the Combobox whose button has this id with a click, and waits for its popover to settle with focus in the search.
 */
function cbOpen(mixed $page, string $id): mixed
{
    $page->click("#{$id}");
    shellSettled($page, '[data-slot="popover-content"]');

    return cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'combobox'");
}

/** A script that is true when the popover is gone and focus is back on this button. */
function cbClosedOn(string $id): string
{
    return "document.querySelector('[data-slot=\"popover-content\"]') === null && document.activeElement === document.getElementById('{$id}')";
}

/** A script for the values of the hidden inputs under this name, joined by commas. */
function cbHidden(string $name): string
{
    return "Array.from(document.querySelectorAll('input[type=hidden][name=\"{$name}\"]')).map((input) => input.value).join(',')";
}

/** A script for the labels of the options shown, joined by commas. */
function cbLabels(): string
{
    return "Array.from(document.querySelectorAll('[role=\"option\"]')).map((option) => option.querySelector('.truncate').textContent).join(',')";
}

it('chooses an author with the keyboard alone: Tab, Enter, two words typed, the arrow and Enter', function (): void {
    $page = cbCatalogue($this->admin);

    // Start on the section's heading, the place just before the Author button.
    $page->script("() => { const heading = document.getElementById('combobox-title'); heading.tabIndex = -1; heading.focus(); }");
    cbPress($page, 'Tab');
    cmWaitUntil($page, "document.activeElement === document.getElementById('catalogue-combobox-single')");

    cbPress($page, 'Enter');
    shellSettled($page, '[data-slot="popover-content"]');
    cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'combobox'");

    cbType($page, 'sam rivers');
    cmWaitUntil($page, cbLabels()." === 'Sam Rivers,Sam Rivers'");

    cbPress($page, 'ArrowDown');
    cbPress($page, 'Enter');
    cmWaitUntil($page, cbClosedOn('catalogue-combobox-single'));

    $page->assertScript("document.getElementById('catalogue-combobox-single').textContent.trim() === 'Sam Rivers'")
        ->assertScript(cbHidden('author')." === 'author-29'");
});

it('moves with Home, End, Page Down and the arrows, one highlighted item at a time', function (): void {
    $page = cbCatalogue($this->admin);
    cbOpen($page, 'catalogue-combobox-single');

    $one = "document.querySelectorAll('[role=\"option\"][aria-selected=\"true\"]').length === 1 && document.querySelector('[role=\"option\"][aria-selected=\"true\"]').id === document.activeElement.getAttribute('aria-activedescendant')";
    $at = fn (int $index): string => "{$one} && Array.from(document.querySelectorAll('[role=\"option\"]')).indexOf(document.querySelector('[role=\"option\"][aria-selected=\"true\"]')) === {$index}";

    cbPress($page, 'ArrowDown');
    cmWaitUntil($page, $at(0));
    cbPress($page, 'ArrowDown');
    cmWaitUntil($page, $at(1));
    cbPress($page, 'End');
    cmWaitUntil($page, $at(29));
    cbPress($page, 'Home');
    cmWaitUntil($page, $at(0));
    cbPress($page, 'PageDown');
    cmWaitUntil($page, $at(10));
    cbPress($page, 'ArrowUp');
    cmWaitUntil($page, $at(9));
});

it('opens with the letter typed on the button already searched', function (): void {
    $page = cbCatalogue($this->admin);

    $page->page()->locator('#catalogue-combobox-single')->focus();
    cbPress($page, 'm');

    shellSettled($page, '[data-slot="popover-content"]');
    cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'combobox' && document.activeElement.value === 'm'");
});

it('closes on Esc, twice, and changes nothing', function (): void {
    $page = cbCatalogue($this->admin);
    cbOpen($page, 'catalogue-combobox-single');

    cbPress($page, 'ArrowDown');
    cbPress($page, 'Escape');
    cmWaitUntil($page, cbClosedOn('catalogue-combobox-single'));
    $page->assertScript(cbHidden('author')." === 'author-3'");

    cbPress($page, 'Escape');
    $page->assertScript("document.getElementById('catalogue-combobox-single').textContent.trim() === 'Barbara Liskov'")
        ->assertScript(cbHidden('author')." === 'author-3'");
});

it('chooses nothing when focus leaves with an item highlighted', function (): void {
    $page = cbCatalogue($this->admin);
    cbOpen($page, 'catalogue-combobox-single');

    cbPress($page, 'ArrowDown');
    cbPress($page, 'ArrowDown');
    cbPress($page, 'Tab');

    cmWaitUntil($page, "document.querySelector('[data-slot=\"popover-content\"]') === null");
    $page->assertScript("document.getElementById('catalogue-combobox-single').textContent.trim() === 'Barbara Liskov'")
        ->assertScript(cbHidden('author')." === 'author-3'");
});

it('keeps two authors with one name apart', function (): void {
    $page = cbCatalogue($this->admin);
    cbOpen($page, 'catalogue-combobox-single');

    cbType($page, 'sam');
    cmWaitUntil($page, cbLabels()." === 'Sam Rivers,Sam Rivers'");
    cbPress($page, 'ArrowDown');
    cbPress($page, 'ArrowDown');
    cbPress($page, 'Enter');
    cmWaitUntil($page, cbClosedOn('catalogue-combobox-single'));
    $page->assertScript(cbHidden('author')." === 'author-30'");

    cbOpen($page, 'catalogue-combobox-single');
    cbType($page, 'sam');
    cmWaitUntil($page, cbLabels()." === 'Sam Rivers,Sam Rivers'");
    $page->assertScript("document.querySelectorAll('[role=\"option\"][data-chosen]').length === 1 && document.querySelector('[role=\"option\"][data-chosen]').textContent.includes('Food desk')");
});

it('ticks several tags, stays open, and lists the chosen first when opened again', function (): void {
    $page = cbCatalogue($this->admin);
    cbOpen($page, 'catalogue-combobox-multi');

    // Opened, the two chosen come first: Ferries and Tides.
    cmWaitUntil($page, cbLabels().".startsWith('Ferries,Tides,Harbor,Islands')");

    cbPress($page, 'ArrowDown');
    cbPress($page, 'ArrowDown');
    cbPress($page, 'ArrowDown');
    cbPress($page, 'Enter');
    cbPress($page, 'ArrowDown');
    cbPress($page, 'Enter');

    cmWaitUntil($page, "document.querySelectorAll('[role=\"option\"][aria-checked=\"true\"]').length === 4");
    $page->assertScript("document.querySelector('[data-slot=\"popover-content\"]') !== null")
        ->assertScript(cbHidden('tags[]')." === 'tag-2,tag-5,tag-1,tag-3'");

    cbPress($page, 'Escape');
    cmWaitUntil($page, cbClosedOn('catalogue-combobox-multi'));

    cbOpen($page, 'catalogue-combobox-multi');
    cmWaitUntil($page, cbLabels().".startsWith('Ferries,Tides,Harbor,Islands,Lighthouses')");
    $page->assertScript("Array.from(document.querySelectorAll('[role=\"option\"]')).slice(0, 4).every((option) => option.getAttribute('aria-checked') === 'true')");
});

it('moves between the chosen tags with the arrows and removes one with Delete', function (): void {
    $page = cbCatalogue($this->admin);

    $tags = "Array.from(document.querySelectorAll('[data-slot=\"combobox-chosen\"] [data-slot=\"status-pill\"]'))";

    // From the button, ← reaches the last tag.
    $page->page()->locator('#catalogue-combobox-multi')->focus();
    cbPress($page, 'ArrowLeft');
    cmWaitUntil($page, "document.activeElement === {$tags}[1]");
    cbPress($page, 'ArrowLeft');
    cmWaitUntil($page, "document.activeElement === {$tags}[0]");
    cbPress($page, 'ArrowRight');
    cmWaitUntil($page, "document.activeElement === {$tags}[1]");

    cbPress($page, 'Delete');
    cmWaitUntil($page, "{$tags}.length === 1 && {$tags}[0].textContent.includes('Ferries')");
    $page->assertScript(cbHidden('tags[]')." === 'tag-2'");
});

it('announces the count, Nothing matches. and Searching…', function (): void {
    $page = cbCatalogue($this->admin);
    $status = "document.getElementById('a11y-status-message')?.textContent";

    cbOpen($page, 'catalogue-combobox-single');
    cbType($page, 'hopper');
    cmWaitUntil($page, "{$status} === '1 result'");
    cbPress($page, 'Escape');
    cmWaitUntil($page, cbClosedOn('catalogue-combobox-single'));

    cbOpen($page, 'catalogue-combobox-empty-list');
    cmWaitUntil($page, "{$status} === 'Nothing matches.'");
    cbPress($page, 'Escape');
    cmWaitUntil($page, cbClosedOn('catalogue-combobox-empty-list'));

    cbOpen($page, 'catalogue-combobox-searching');
    cmWaitUntil($page, "{$status} === 'Searching…'");
    cbPress($page, 'Escape');
    cmWaitUntil($page, cbClosedOn('catalogue-combobox-searching'));

    $page->page()->locator('#catalogue-combobox-multi')->focus();
    cbPress($page, 'ArrowLeft');
    cbPress($page, 'Delete');
    cmWaitUntil($page, "{$status} === 'Tides removed'");
});

it('passes axe with each one open, every target 24 pixels or more, at 1280 and 375 pixels in both schemes', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cbCatalogue($this->admin, $width);

    foreach (['catalogue-combobox-single', 'catalogue-combobox-multi'] as $id) {
        cbOpen($page, $id);
        $page->assertNoAccessibilityIssues(3);

        $targets = cmSmallTargets($page);

        expect($targets['small'])->toBe([])
            ->and($targets['checked'])->toBeGreaterThan(0);

        cbPress($page, 'Escape');
        cmWaitUntil($page, cbClosedOn($id));
    }
})->with(['light', 'dark'])->with([1280, 375]);

/**
 * The tag ids the item's working revision holds.
 *
 * @return list<string>
 */
function cbSavedTerms(Content $item): array
{
    $revision = Content::query()->whereKey($item->id)->value('working_revision_id');

    return json_decode((string) DB::table('content_revisions')->where('id', $revision)->value('terms'), true);
}

/**
 * The editor of this item, settled, with focus moved by Tab from the Tags section's header to its button.
 */
function cbEditorAtTags(User $person, Content $item): mixed
{
    $page = cmNavigate(cmSignIn($person)->resize(1280, 900), "/admin/content/{$item->id}/edit");
    cmEditorSettled($page);

    $page->page()->locator('button:has(h2:text-is("Tags"))')->focus();
    cbPress($page, 'Tab');

    return cmWaitUntil($page, "document.activeElement?.dataset.slot === 'combobox-trigger'");
}

it('finds and chooses a tag in the editor with the keyboard alone, and keeps it on save', function (): void {
    $harbour = taxonomyTerm('tag', 'Harbour');
    $item = lifecycleDraft($this->admin, 'Ferry notes');

    $page = cbEditorAtTags($this->admin, $item);

    cbType($page, 'harb');
    shellSettled($page, '[data-slot="popover-content"]');
    cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'combobox' && document.activeElement.value === 'harb'");
    cmWaitUntil($page, "document.querySelector('[data-slot=\"combobox-searching\"]') === null && ".cbLabels()." === 'Harbour'");

    cbPress($page, 'Enter');
    cmWaitUntil($page, "document.querySelector('[role=\"option\"]')?.getAttribute('aria-checked') === 'true'");
    cbPress($page, 'Escape');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"popover-content\"]') === null");
    cmWaitUntil($page, "Array.from(document.querySelectorAll('[data-slot=\"combobox-chosen\"] [data-slot=\"status-pill\"]')).map((tag) => tag.textContent.trim()).join(',') === 'Harbour' && document.querySelector('[data-slot=\"status-pill-remove\"][aria-label=\"Remove Harbour\"]') !== null");

    $page->keys('.teme-prose', 'Control+s');
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'");

    expect(cbSavedTerms($item))->toContain($harbour->id);
});
