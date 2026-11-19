<?php

declare(strict_types=1);

use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Every admin screen, opened as a person opens it, must open without a script
 * error. With TEME_FULL_PASS=1, each screen's words, controls and screenshots
 * are also written down for the screen-by-screen pass against the design
 * (docs/architecture/admin-design.md, The parity sweep list).
 */

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    // One site with something on every screen.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/quiet')]]);
    themeActivate('good');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
    $this->contributor = User::factory()->create(['name' => 'Cy Contributor'])->assignRole('contributor');

    // Inviting asks for elevation, as the screen's password check gives it; then back to the default.
    $this->withElevation();
    app(InviteUser::class)->execute(new InviteUserInput(name: 'Ivy Invited', email: 'ivy@example.com', role: 'contributor', actorId: (string) $this->admin->id, deliver: false));
    $this->app->instance(Elevation::class, new DeniedElevation);

    sdCategory('Harbour', sdCategory('News'));
    taxonomyTerm('tag', 'Ferries');
    $this->post = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday.');
    listsPublish($this->post, $this->author);
    addrPublished($this->admin, 'About the harbour', 'page');
    $this->draft = lifecycleDraft($this->contributor, 'Ferry tips');
    cmComment($this->post->refresh(), 'pending');
    cmComment($this->post);

    $image = mediaUpload($this->admin, mediaJpeg());
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($image->id, (string) $this->admin->id, 'Quay', 'Boats at the quay.', 'At dawn.', 'Ada Okafor', licence: 'CC BY 4.0'));

    $this->menu = navCreate($this->admin);
    regionCreate($this->admin);
    rdSaved('/old-fares', item: $this->post->id);
    rdLogged(['news/ferry-fares-2025' => 12], 'www.google.com');
    actvRow($this->author, ['subjectId' => $this->post->id]);

    // AI ready for all three tasks, so the editor, the media details and Settings › AI show every surface they have.
    rewriteReady();
    aiChoose('metadata', 'fake:fake-text');
    aiChoose('alt-text', 'fake:fake-vision');

    // What a screen holds, as words: the menu entry marked current, the
    // headings, tabs, controls, columns, labels and the main text.
    $this->inventory = <<<'JS'
        (() => {
            const main = document.querySelector('[data-slot="sheet-content"]') ?? document.querySelector('main') ?? document.body;
            const words = (element) => (element.getAttribute('aria-label') || element.textContent || '').replace(/\s+/g, ' ').trim();
            const shown = (element) => element.getClientRects().length > 0;
            const all = (root, selector) => [...root.querySelectorAll(selector)].filter(shown).map(words).filter((text) => text !== '');
            const h1 = document.querySelector('h1');
            const line = h1?.parentElement?.querySelector('p') ?? null;

            return {
                url: location.pathname + location.search,
                title: document.title,
                current: all(document, '[data-slot="sidebar"] [aria-current="page"], [data-slot="sidebar"] [data-active="true"]'),
                menu: all(document, '[data-slot="sidebar"] a'),
                breadcrumbs: all(document, 'nav[aria-label="Breadcrumb"] li, nav[aria-label="breadcrumb"] li'),
                h1: h1 === null ? null : words(h1),
                h1Count: document.querySelectorAll('h1').length,
                line: line === null ? null : words(line),
                headings: all(main, 'h2, h3'),
                tabs: all(main, '[role="tab"], nav[aria-label="Views"] a'),
                buttons: all(main, 'button, a[href], [role="button"]'),
                columns: all(main, 'th'),
                labels: all(main, 'label, legend'),
                placeholders: [...main.querySelectorAll('input[placeholder], textarea[placeholder]')].filter(shown).map((field) => field.getAttribute('placeholder')),
                mainText: main.innerText.slice(0, 6000),
            };
        })()
        JS;
});

it('opens every admin screen with its heading and no script error', function (string $key, string $path, string $role, string $pane): void {
    $person = match ($role) {
        'author' => $this->author,
        'contributor' => $this->contributor,
        'nobody' => null,
        default => $this->admin,
    };
    $url = strtr($path, ['{post}' => $this->post->id, '{draft}' => $this->draft->id, '{menu}' => (string) $this->menu->id]);

    // Warm the screen before the browser's first visit (I-638); a screen behind the password check, with it passed.
    $warm = $person === null ? $this : $this->actingAs($person);
    ($role === 'confirmed' ? $warm->withSession(['auth.password_confirmed_at' => time()]) : $warm)->get($url)->assertOk();

    if ($person === null) {
        $page = visit($url);
    } elseif ($role === 'confirmed') {
        // The password confirmed first, as the screen asks.
        $page = cmSignIn($person)
            ->navigate(route('password.confirm', [], false))
            ->type('#password', 'password')
            ->click('[data-test="confirm-password-button"]');
        cmWaitUntil($page, "location.pathname === '/admin'");
        $page->navigate($url);
    } else {
        $page = cmSignIn($person)->navigate($url);
    }

    if (str_ends_with($path, '/edit')) {
        cmEditorSettled($page);
    }

    if ($pane === 'Assistant' || $pane === 'History') {
        $pane === 'Assistant' ? $page->keys('.teme-prose', 'Control+j') : $page->click('[aria-label="History"]');
        cmWaitUntil($page, 'document.querySelector('.json_encode('[aria-label="'.$pane.'"][aria-pressed="true"]', JSON_THROW_ON_ERROR).') !== null');

        if ($pane === 'History') {
            // History fetches its revisions once it opens.
            cmWaitUntil($page, "document.querySelector('p[aria-busy=\"true\"]') === null");
        }
    } elseif ($pane !== '') {
        // A file's details open from its tile, in the panel beside the library.
        $page->click('[role="option"]:has-text('.json_encode($pane, JSON_THROW_ON_ERROR).')');
        cmWaitUntil($page, "(() => { const panel = document.querySelector('[data-test=\"media-detail\"]'); return panel !== null && panel.innerText.includes(".json_encode($pane, JSON_THROW_ON_ERROR).'); })()');
    }

    // The editor has settled above; one more script call on it can return the plugin's stale `true` frame (B-793).
    if (! str_ends_with($path, '/edit')) {
        cmWaitUntil($page, "document.readyState === 'complete' && document.querySelector('h1') !== null");
    }
    cmNoJavaScriptErrors($page);

    if (getenv('TEME_FULL_PASS') !== '1') {
        return;
    }

    $inventory = cmScriptArray($page, $this->inventory);
    $small = cmSmallTargets($page);
    $page->screenshot(true, 'full-pass-'.$key);

    $page->resize(390, 844);
    cmWaitUntil($page, 'window.innerWidth === 390');
    $sideways = (int) $page->script('document.documentElement.scrollWidth - document.documentElement.clientWidth');
    $page->screenshot(true, 'full-pass-'.$key.'-390');

    $file = storage_path("framework/testing/full-pass/{$key}.json");
    File::ensureDirectoryExists(dirname($file));
    File::put($file, json_encode([...$inventory, 'smallTargets' => $small, 'sidewaysAt390' => $sideways], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    expect($inventory['h1'] ?? null)->not->toBeNull()
        ->and(File::exists($file))->toBeTrue();
})->with([
    'home' => ['home', '/admin', 'admin', ''],
    'sign-in' => ['sign-in', '/admin/login', 'nobody', ''],
    'profile' => ['profile', '/admin/account/profile', 'admin', ''],
    'security' => ['security', '/admin/account/security', 'confirmed', ''],
    'preferences' => ['preferences', '/admin/account/preferences', 'admin', ''],
    'posts' => ['posts', '/admin/posts', 'admin', ''],
    'posts-trash' => ['posts-trash', '/admin/posts?tab=trash', 'admin', ''],
    'pages' => ['pages', '/admin/pages', 'admin', ''],
    'editor' => ['editor', '/admin/content/{post}/edit', 'admin', ''],
    'editor-assistant' => ['editor-assistant', '/admin/content/{post}/edit', 'admin', 'Assistant'],
    'editor-history' => ['editor-history', '/admin/content/{post}/edit', 'admin', 'History'],
    'compare' => ['compare', '/admin/content/{post}/compare', 'admin', ''],
    'categories' => ['categories', '/admin/categories', 'admin', ''],
    'tags' => ['tags', '/admin/tags', 'admin', ''],
    'comments' => ['comments', '/admin/comments', 'admin', ''],
    'media' => ['media', '/admin/media', 'admin', ''],
    'media-drawer' => ['media-drawer', '/admin/media', 'admin', 'Quay'],
    'navigation' => ['navigation', '/admin/navigation', 'admin', ''],
    'navigation-edit' => ['navigation-edit', '/admin/navigation/{menu}', 'admin', ''],
    'regions' => ['regions', '/admin/regions', 'admin', ''],
    'themes' => ['themes', '/admin/themes', 'admin', ''],
    'people' => ['people', '/admin/users', 'admin', ''],
    'tools' => ['tools', '/admin/tools', 'admin', ''],
    'page-cache' => ['page-cache', '/admin/tools/page-cache', 'admin', ''],
    'redirects' => ['redirects', '/admin/tools/redirects', 'admin', ''],
    'not-found' => ['not-found', '/admin/tools/redirects?tab=not-found', 'admin', ''],
    'ai-activity' => ['ai-activity', '/admin/tools/ai-activity', 'admin', ''],
    'settings-general' => ['settings-general', '/admin/settings/general', 'admin', ''],
    'settings-writing' => ['settings-writing', '/admin/settings/writing', 'admin', ''],
    'settings-reading' => ['settings-reading', '/admin/settings/reading', 'admin', ''],
    'settings-media' => ['settings-media', '/admin/settings/media', 'admin', ''],
    'settings-discussion' => ['settings-discussion', '/admin/settings/discussion', 'admin', ''],
    'settings-addresses' => ['settings-addresses', '/admin/settings/addresses', 'admin', ''],
    'settings-people' => ['settings-people', '/admin/settings/people', 'admin', ''],
    'settings-seo' => ['settings-seo', '/admin/settings/seo', 'admin', ''],
    'settings-site-code' => ['settings-site-code', '/admin/settings/site-code', 'admin', ''],
    'settings-ai' => ['settings-ai', '/admin/settings/ai', 'admin', ''],
    'comments-as-author' => ['comments-as-author', '/admin/comments', 'author', ''],
    'posts-as-contributor' => ['posts-as-contributor', '/admin/posts', 'contributor', ''],
    'editor-as-contributor' => ['editor-as-contributor', '/admin/content/{draft}/edit', 'contributor', ''],
]);
