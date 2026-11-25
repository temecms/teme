<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\StoredTaxonomies;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

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

    // Films, and Genres: nested, used by Films and Posts, with Action, Martial arts under it, and a film that has Action.
    $this->site = function (): void {
        ctCreate($this->admin, ['taxonomies' => []]);
        txCreate($this->admin, [], ['film', 'post']);
        $action = typesTerm($this->admin, 'genre', 'Action');
        typesTerm($this->admin, 'genre', 'Martial arts', $action->id);
        typesDraft($this->admin, 'film', 'Arrival', [$action->id]);
    };

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/taxonomies')->assertOk();
});

it('makes Studios from the form, used by Films, and the menu files them under Films at once; Set up Studios leads back', function (): void {
    ctCreate($this->admin, ['taxonomies' => []]);

    $page = cmSignIn($this->admin)->navigate('/admin/taxonomies/new-taxonomy');
    ($this->seen)($page, 'Its term screen appears in the menu as soon as it’s added.');

    $page->type('#taxonomy-singular', 'Studio')
        ->type('#taxonomy-plural', 'Studios')
        ->type('#taxonomy-key', 'studio')
        ->type('#taxonomy-segment', 'studios')
        ->type('#taxonomy-base', 'studio')
        ->click('#taxonomy-type-film')
        ->click('[data-test="taxonomy-save"]');
    cmWaitUntil($page, 'location.pathname === "/admin/taxonomies/studio"');
    ($this->seen)($page, 'Added Studios.');

    expect(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['studio']);

    $page->navigate('/admin/studios');
    cmWaitUntil($page, 'document.querySelector(\'nav a[href="/admin/studios"]\') !== null');
    expect($page->script('() => document.querySelector("[data-test=\"term-setup\"]")'))->toBeNull();

    $page->navigate('/admin/taxonomies/studio');
    cmWaitUntil($page, 'location.pathname === "/admin/taxonomies/studio"');
    ($this->seen)($page, 'Made here on');
});

it('changes Genres while it has terms under others and on items, each control that can’t change saying why beside it, the Save bar in view', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->navigate('/admin/taxonomies/genre');
    ($this->seen)($page, '1 genre sits under others, so Genres can’t become flat. Move it to the top first.');
    ($this->seen)($page, 'Films has items with genres chosen, so it can’t be taken off. Remove the genres from its items first.');
    ($this->seen)($page, 'Genres have terms (2), so whether they have public pages can’t change.');

    expect($page->script('() => [document.querySelector("#taxonomy-flat").disabled, document.querySelector("#taxonomy-nested").disabled, document.querySelector("#taxonomy-type-film").disabled, document.querySelector("#taxonomy-type-post").disabled, document.querySelector("#taxonomy-public").disabled]'))->toBe([true, true, true, false, true])
        // A ticked control that can't change is still sent as it stands: a disabled input never is.
        ->and($page->script('() => [document.querySelectorAll(\'main form input[type="hidden"][name="hierarchical"][value="1"]\').length, document.querySelectorAll(\'main form input[type="hidden"][name="types[]"][value="film"]\').length, document.querySelectorAll(\'main form input[type="hidden"][name="public"][value="1"]\').length]'))->toBe([1, 1, 1])
        ->and($page->script('() => { const bar = document.querySelector("[data-test=\"save-bar\"]").getBoundingClientRect(); return bar.top >= 0 && bar.bottom <= window.innerHeight; }'))->toBeTrue();

    $page->type('#taxonomy-plural', 'Kinds')->click('[data-test="taxonomy-save"]');
    ($this->seen)($page, 'Saved Kinds.');

    expect(app(TaxonomyRegistry::class)->get('genre')->plural)->toBe('Kinds')
        ->and(app(TaxonomyRegistry::class)->get('genre')->hierarchical)->toBeTrue()
        ->and(app(ContentTypeRegistry::class)->get('film')->taxonomies)->toBe(['genre']);
});

it('passes axe on the list, on Genres, on a new taxonomy and on Categories', function (string $scheme): void {
    ($this->site)();
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin);

    foreach ([
        '/admin/taxonomies' => ['list', 'The ways this site groups its content, such as categories and tags.'],
        '/admin/taxonomies/genre' => ['genre', '2 terms.'],
        '/admin/taxonomies/new-taxonomy' => ['new', 'Its term screen appears in the menu as soon as it’s added.'],
        '/admin/taxonomies/category' => ['category', 'Categories are declared by code, so they can’t be changed or deleted here.'],
    ] as $path => [$name, $words]) {
        $page->navigate($path);
        ($this->seen)($page, $words);

        expect(cmAxeViolations($page))->toBe([]);

        // A look at the three pages (the list, a new taxonomy and an existing one), in both schemes.
        $page->screenshot(true, "taxonomies-{$name}-{$scheme}");
    }
})->with(['light', 'dark']);

it('draws the list and Genres at 375 pixels with nothing scrolling sideways or cut off, passing axe', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->resize(375, 812)->navigate('/admin/taxonomies');
    ($this->seen)($page, 'The ways this site groups its content, such as categories and tags.');

    expect(($this->sideways)($page))->toBe(0)
        ->and(cmAxeViolations($page))->toBe([]);

    $page->navigate('/admin/taxonomies/genre');
    ($this->seen)($page, 'Delete this taxonomy');

    expect(($this->sideways)($page))->toBe(0)
        ->and($page->script('() => [...document.querySelectorAll("main input, main button, main a")].every((element) => element.getBoundingClientRect().right <= document.documentElement.clientWidth + 0.5)'))->toBeTrue()
        ->and(cmAxeViolations($page))->toBe([]);
});

it('deletes Studios after asking, and the menu loses them; Genres offer no Delete, saying why', function (): void {
    ($this->site)();
    txCreate($this->admin, ['key' => 'studio', 'singular' => 'Studio', 'plural' => 'Studios', 'segment' => 'studios', 'base' => 'studio', 'hierarchical' => false], ['film']);

    $page = cmSignIn($this->admin)->navigate('/admin/taxonomies/genre');
    ($this->seen)($page, 'Genres have 2 terms, so the taxonomy can’t be deleted. Delete them first.');

    expect($page->script('() => document.querySelector("[data-test=\"taxonomy-delete\"]")'))->toBeNull();

    $page->navigate('/admin/taxonomies/studio');
    ($this->seen)($page, 'Delete this taxonomy');
    $page->click('[data-test="taxonomy-delete"]');
    cmWaitUntil($page, $this->dialogSettled.' && document.querySelector(\'[role="dialog"]\').innerText.includes("Delete Studios?")');

    $page->click('[role="dialog"] button:has-text("Delete Studios")');
    cmWaitUntil($page, 'location.pathname === "/admin/taxonomies"');
    ($this->seen)($page, 'Deleted Studios.');
    cmWaitUntil($page, 'document.querySelector(\'nav a[href="/admin/studios"]\') === null');

    expect(DB::table(StoredTaxonomies::TABLE)->pluck('key')->all())->toBe(['genre']);
});

it('shows the lock’s notice, and only View, while types are locked', function (): void {
    ($this->site)();
    config(['teme.types_locked' => true]);

    $page = cmSignIn($this->admin)->navigate('/admin/taxonomies');
    ($this->seen)($page, 'You can still look at each taxonomy, and download teme-types.json.');

    expect($page->script('() => [document.querySelector("[data-test=\"taxonomies-add\"]"), [...document.querySelectorAll("main a, main button")].filter((element) => ["Edit", "Edit to fix", "Delete"].includes(element.textContent.trim())).length]'))->toBe([null, 0]);

    $page->navigate('/admin/taxonomies/genre');
    ($this->seen)($page, 'Taxonomies are locked on this site (TEME_TYPES_LOCKED)');

    expect($page->script('() => document.querySelectorAll("main form").length'))->toBe(0);
});

it('offers no Set up on Genres, and names a genre’s page in its edit sheet', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->navigate('/admin/genres');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="term-name"]\') !== null');
    expect($page->script('() => document.querySelector("[data-test=\"term-setup\"]")'))->toBeNull()
        ->and($page->script('() => document.querySelector(\'nav[aria-label="Taxonomies"]\')'))->toBeNull();

    $page->click('[aria-label="Actions for Action"] >> text="Edit"');
    cmWaitUntil($page, 'document.querySelector(\'[role="menu"]\') === null');
    shellSettled($page, '[data-slot="sheet-content"], [data-slot="sheet-overlay"]');
    ($this->seen)($page, 'Its page: /genre/action/, listing 1 film.');
});

it('shows an editor no Set up on the term screen', function (): void {
    ($this->site)();

    $page = cmSignIn($this->editor)->navigate('/admin/genres');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="term-name"]\') !== null');

    expect($page->script('() => document.querySelector("[data-test=\"term-setup\"]")'))->toBeNull();
});

it('heads Genres’ page with its crumbs and the way back, with no row of tabs', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->navigate('/admin/taxonomies/genre');
    ($this->seen)($page, 'All taxonomies');

    expect($page->script('() => document.querySelector(\'nav[aria-label="Sections of this taxonomy"]\')'))->toBeNull()
        ->and($page->script('() => [...document.querySelectorAll(\'[data-slot="breadcrumb-item"]\')].map((item) => item.innerText.trim())'))->toBe(['Site', 'Taxonomies', 'Genres'])
        ->and($page->script('() => document.querySelector(\'main a[href="/admin/taxonomies"]\').innerText.trim()'))->toBe('All taxonomies');
});
