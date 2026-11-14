<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTypes;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->admin = ctAdmin();

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');
    // How far the page scrolls sideways: none. The kept scrollbar gutter makes the raw difference negative.
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');
    $this->dialogSettled = 'document.querySelector(\'[role="dialog"]\') !== null && document.querySelector(\'[role="dialog"]\').getAnimations().every((a) => a.playState !== "running")';

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/types')->assertOk();
});

it('makes Films from the form, its icon picked from every Lucide icon, and the menu draws it at once', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/types/new-type');
    ($this->seen)($page, 'It appears in the menu as soon as it’s added.');

    $page->type('#type-singular', 'Film')
        ->type('#type-plural', 'Films')
        ->type('#type-key', 'film')
        ->type('#type-segment', 'films')
        ->click('[data-test="type-icon-change"]');
    cmWaitUntil($page, $this->dialogSettled.' && document.querySelector(\'[role="dialog"] #icon-search\') !== null');

    $page->type('#icon-search', 'clapper');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"] button[aria-label="clapperboard"] svg path\') !== null');
    $page->click('[role="dialog"] button[aria-label="clapperboard"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null && document.querySelector(\'input[name="icon"]\').value === "clapperboard"');

    $page->click('[data-test="type-save"]');
    cmWaitUntil($page, 'location.pathname === "/admin/types/film"');
    ($this->seen)($page, 'Added Films.');
    cmWaitUntil($page, 'document.querySelector(\'a[data-slot="sidebar-menu-button"][href="/admin/films"] svg[data-icon="clapperboard"] path\') !== null');
});

it('changes Films while they have items, each control that can’t change saying why beside it, the Save bar in view', function (): void {
    tbSite($this->admin);

    $page = cmSignIn($this->admin)->navigate('/admin/types/film');
    ($this->seen)($page, 'Films have items (3, trashed ones included), so whether they’re public can’t change.');
    ($this->seen)($page, 'Films have 3 items, so they can’t become one item only.');

    expect($page->script('() => [document.querySelector("#type-public").disabled, document.querySelector("#type-single").disabled]'))->toBe([true, true])
        // A ticked control that can't change is still sent as it stands: a disabled input never is.
        ->and($page->script('() => document.querySelectorAll(\'main form input[type="hidden"][name="public"][value="1"]\').length'))->toBe(1);

    $page->type('#type-plural', 'Movies');

    // The bar shows once there is a change, inside the window.
    expect($page->script('() => { const bar = document.querySelector("[data-test=\"save-bar\"]"); const box = bar.getBoundingClientRect(); return !bar.hidden && box.height > 0 && box.top >= 0 && box.bottom <= window.innerHeight; }'))->toBeTrue();

    $page->click('[data-test="type-save"]');
    ($this->seen)($page, 'Saved Movies.');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === true');

    expect(app(ContentTypeRegistry::class)->get('film')->public)->toBeTrue()
        ->and(app(ContentTypeRegistry::class)->get('film')->plural)->toBe('Movies');
});

it('gives Films their own permissions from the Permissions panel, and shows who holds each', function (): void {
    tbSite($this->admin);

    $page = cmSignIn($this->admin)->navigate('/admin/types/film');
    ($this->seen)($page, 'What each role may do with every type’s items');

    $page->click('#type-permissions-own');
    ($this->seen)($page, 'When you save, each role starts with what it may do now, so nobody gains or loses anything.');

    $page->click('[data-test="type-save"]');
    ($this->seen)($page, 'Saved Films.');
    ($this->seen)($page, 'What each role may do with Films');

    expect($page->script('() => document.querySelectorAll(\'[data-test="type-permissions-table"] tbody tr\').length'))->toBe(6)
        ->and($page->script('() => document.querySelector(\'[data-test="type-permissions-table"] tbody tr:nth-child(4) code\').textContent'))->toBe('film.publish')
        ->and($page->script('() => document.querySelector("#type-permissions-own").getAttribute("aria-checked") === "true"'))->toBeTrue()
        ->and(app(ContentTypeRegistry::class)->get('film')->ability('publish'))->toBe('film.publish');
});

it('keeps the permissions table inside the page at 375 pixels, with four roles and with five', function (): void {
    tbSite($this->admin);
    $region = '[role="region"][tabindex="0"] [data-test="type-permissions-table"]';

    $page = cmSignIn($this->admin)->resize(375, 812)->navigate('/admin/types/film');
    ($this->seen)($page, 'What each role may do with every type’s items');

    expect(($this->sideways)($page))->toBe(0)
        ->and($page->script('() => document.querySelector(\''.$region.'\') !== null'))->toBeTrue()
        ->and(cmAxeViolations($page))->toBe([]);

    Role::findOrCreate('reviewer')->givePermissionTo('content.edit.any');

    $page->navigate('/admin/types/film');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="type-permissions-table"] thead th\').length === 6');

    expect(($this->sideways)($page))->toBe(0)
        ->and(cmAxeViolations($page))->toBe([]);
});

it('passes axe on the list, on Films and on Posts', function (string $scheme): void {
    tbSite($this->admin);
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin);

    foreach ([
        '/admin/types' => 'Can’t be used: Content type [event] uses taxonomy [venue], which is not registered.',
        '/admin/types/film' => 'Changes reach the menu, the editor and the site as soon as they’re saved.',
        '/admin/types/post' => 'Posts are declared by code, so they can’t be changed or deleted here.',
    ] as $path => $words) {
        $page->navigate($path);
        ($this->seen)($page, $words);

        expect(cmAxeViolations($page))->toBe([]);
    }
})->with(['light', 'dark']);

it('draws the list and Films at 375 pixels with nothing scrolling sideways or cut off, passing axe', function (): void {
    tbSite($this->admin);

    $page = cmSignIn($this->admin)->resize(375, 812)->navigate('/admin/types');
    ($this->seen)($page, 'Can’t be used: Content type [event] uses taxonomy [venue], which is not registered.');

    expect(($this->sideways)($page))->toBe(0)
        ->and(cmAxeViolations($page))->toBe([]);

    $page->navigate('/admin/types/film');
    ($this->seen)($page, 'Delete this type');

    expect(($this->sideways)($page))->toBe(0)
        ->and($page->script('() => [...document.querySelectorAll("main input, main button, main a")].every((element) => element.getBoundingClientRect().right <= document.documentElement.clientWidth + 0.5)'))->toBeTrue()
        ->and(cmAxeViolations($page))->toBe([]);
});

it('deletes Recipes after asking, and the menu loses them', function (): void {
    tbSite($this->admin);

    $page = cmSignIn($this->admin)->navigate('/admin/types/recipe');
    ($this->seen)($page, 'No items use it, so it can be deleted.');
    $page->click('[data-test="type-delete"]');
    cmWaitUntil($page, $this->dialogSettled.' && document.querySelector(\'[role="dialog"]\').innerText.includes("Delete Recipes?")');

    $page->click('[role="dialog"] button:has-text("Delete Recipes")');
    cmWaitUntil($page, 'location.pathname === "/admin/types"');
    ($this->seen)($page, 'Deleted Recipes.');
    cmWaitUntil($page, 'document.querySelector(\'a[data-slot="sidebar-menu-button"][href="/admin/recipes"]\') === null');
});

it('shows the lock’s notice, and only View, while types are locked', function (): void {
    tbSite($this->admin);
    config(['teme.types_locked' => true]);

    $page = cmSignIn($this->admin)->navigate('/admin/types');
    ($this->seen)($page, 'You can still look at each type, and download teme-types.json.');

    expect($page->script('() => [document.querySelector("[data-test=\"types-add\"]"), [...document.querySelectorAll("main a, main button")].filter((element) => ["Edit", "Edit to fix", "Delete"].includes(element.textContent.trim())).length]'))->toBe([null, 0]);
});

it('draws the menu with a circle for a type whose icon names something on every object, such as constructor', function (): void {
    ctCreate($this->admin, ['icon' => 'constructor']);

    $page = cmSignIn($this->admin)->navigate('/admin/types');

    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Main"] a[href="/admin/films"]\') !== null');
    ($this->seen)($page, 'Films');
});

it('saves a type that isn’t public, has items and was saved as searchable, its locked boxes sent as off', function (): void {
    ctCreate($this->admin, ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'icon' => 'file', 'public' => false, 'archive' => false, 'searchable' => false, 'comments' => null, 'taxonomies' => [], 'supports' => []]);
    typesDraft($this->admin, 'note', 'First note');

    // A row a reader gives searchable by default. A type that isn't public can't be searched, so a form that
    // sent its locked box as ticked was refused on every save.
    DB::table(StoredTypes::TABLE)->where('key', 'note')->update(['definition' => DB::raw("definition - 'searchable'")]);
    app(StoredTypes::class)->remember();
    app(ContentTypeRegistry::class)->refreshStored();

    $page = cmSignIn($this->admin)->navigate('/admin/types/note');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="type-save"]\') !== null');

    // The save bar shows once something changes.
    $page->type('#type-singular', 'Memo')->click('[data-test="type-save"]');
    ($this->seen)($page, 'Saved Notes.');

    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(ContentTypeRegistry::class)->get('note')->searchable)->toBeFalse()
        ->and(app(ContentTypeRegistry::class)->get('note')->public)->toBeFalse();
});

it('preloads at most 48 files on the media screen, the whole icon set staying one lazy file', function (): void {
    // I-3311: lucide-react/dynamic split every icon into its own file and roughly doubled each admin page's
    // preloads (media 34 to 64). One lazy file for the set measured 42 on /admin/media; 48 leaves a margin.
    $page = cmSignIn($this->admin)->navigate('/admin/media');
    cmWaitUntil($page, 'document.querySelector(\'a[data-slot="sidebar-menu-button"]\') !== null');

    expect((int) $page->script('() => document.querySelectorAll(\'link[rel="modulepreload"]\').length'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(48);

    // The set's file is the largest of the chunk Rolldown names after resources/js/lib/lucide-icons.ts and the
    // chunks it imports: the set itself unless a code-splitting group moved it out. It must never be preloaded.
    $manifest = collect(json_decode((string) file_get_contents(public_path('build/manifest.json')), true));
    $entry = $manifest->first(fn (array $chunk): bool => ($chunk['name'] ?? null) === 'lucide-icons');

    expect($entry)->not->toBeNull();

    $iconSet = collect([$entry, ...array_map(fn (string $key): array => $manifest[$key], $entry['imports'] ?? [])])
        ->sortByDesc(fn (array $chunk): int => (int) filesize(public_path('build/'.$chunk['file'])))
        ->first()['file'];

    $preloaded = $page->script('() => [...document.querySelectorAll(\'link[rel="modulepreload"]\')].map((link) => link.href)');

    expect(collect($preloaded)->filter(fn (string $href): bool => str_ends_with($href, $iconSet)))->toBeEmpty();
});

it('moves between Films\' sections from the row of tabs without leaving the page, and keeps what was typed', function (): void {
    tbSite($this->admin);

    // A view shorter than the form, so the page can scroll Permissions, near its foot, to the top.
    $page = cmNavigate(cmSignIn($this->admin)->resize(1440, 700), '/admin/types/film');
    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Sections of this type"]\') !== null');

    $page->append('#type-plural', ' (new)');
    $page->click('nav[aria-label="Sections of this type"] a[href="#type-section-permissions"]');

    // The view: the nearest box that scrolls the section, else the window.
    cmWaitUntil($page, <<<'JS'
        (() => {
            const section = document.querySelector("#type-section-permissions");
            let view = section.parentElement;

            while (view !== null && !(['auto', 'scroll'].includes(getComputedStyle(view).overflowY) && view.scrollHeight > view.clientHeight)) {
                view = view.parentElement;
            }

            const top = view === null ? 0 : Math.max(0, view.getBoundingClientRect().top);

            return Math.abs(section.getBoundingClientRect().top - top) <= 80;
        })()
        JS);

    expect($page->script('() => document.querySelector(\'nav[aria-label="Sections of this type"] a[href="#type-section-permissions"]\').getAttribute("aria-current")'))->toBe('location')
        ->and($page->script('() => location.pathname'))->toBe('/admin/types/film')
        ->and($page->script('() => document.querySelector(\'[data-test="leave-guard"]\') === null'))->toBeTrue()
        ->and($page->script('() => document.querySelector("#type-plural").value.endsWith(" (new)")'))->toBeTrue()
        ->and($page->script('() => document.querySelector(\'[data-test="save-bar"]\').hidden'))->toBeFalse();
});

it('shows the save bar when only the icon changes, and Discard puts the icon back', function (): void {
    tbSite($this->admin);
    $savedIcon = app(ContentTypeRegistry::class)->get('film')->icon;

    $page = cmSignIn($this->admin)->navigate('/admin/types/film');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="type-icon-change"]\') !== null');

    $page->click('[data-test="type-icon-change"]');
    cmWaitUntil($page, $this->dialogSettled.' && document.querySelector(\'[role="dialog"] #icon-search\') !== null');

    $page->type('#icon-search', 'clapper');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"] button[aria-label="clapperboard"] svg path\') !== null');
    $page->click('[role="dialog"] button[aria-label="clapperboard"]');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null && document.querySelector(\'input[name="icon"]\').value === "clapperboard"');

    expect($page->script('() => document.querySelector(\'[data-test="save-bar"]\').hidden'))->toBeFalse();

    $page->click('[data-test="save-bar-discard"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === true');

    expect($page->script('() => document.querySelector(\'input[name="icon"]\').value'))->toBe($savedIcon);
});
