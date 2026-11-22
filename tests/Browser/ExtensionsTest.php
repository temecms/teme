<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Installing a plugin from the admin (docs/plans/plugin-installer.md, part B).

require_once __DIR__.'/../Feature/Extensions/Admin/support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    Cache::flush();
    config(['teme.extensions.packagist' => true]);
    extFolder();
    Http::fake([
        'packagist.org/search.json*' => Http::response(['results' => [
            ['name' => 'acme/link-suggestions', 'description' => 'Suggests links between posts.', 'downloads' => 1234],
        ]]),
        'repo.packagist.org/p2/*' => Http::response(['packages' => ['acme/link-suggestions' => [
            ['version' => '1.4.0'], ['version' => '1.3.2'],
        ]]]),
    ]);
    $this->admin = User::factory()->create()->assignRole('administrator');

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('plugins.index'))->assertOk();
});

it('asks for the password, records a Packagist install, and follows it in the request sheet, with axe, at each width and scheme', function (int $width, string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    $fits = 'document.documentElement.scrollWidth <= document.documentElement.clientWidth';

    $page = cmSignIn($this->admin)->resize($width, $width === 375 ? 812 : 900)->navigate('/admin/plugins');
    $page->click('[data-test="plugins-add"]');
    shellSettled($page, '[data-test="extension-add-sheet"]');
    $page->assertSee('Teme asks for your password, records the request, and the installer carries it out. Nothing is enabled until you enable it.')
        ->assertNoAccessibilityIssues(3)
        ->assertScript($fits, true);

    foreach (['git', 'composer', 'upload', 'packagist'] as $tab) {
        $page->click("[data-test=\"extension-tab-{$tab}\"]");
        cmWaitUntil($page, "document.querySelector('[data-test=\"extension-tab-{$tab}\"]').dataset.state === 'active'");
        $page->assertNoAccessibilityIssues(3)->assertScript($fits, true);
    }

    $page->type('#extension-search', 'links');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="extension-result"]\') !== null');
    $page->click('[data-test="extension-choose"]');
    cmWaitUntil($page, 'document.querySelector("#extension-version")?.textContent.includes("The newest that fits (1.4.0 now)") === true');
    $page->assertSee('1,234 installs')
        ->assertNoAccessibilityIssues(3)
        ->assertScript($fits, true);

    if ($width === 375) {
        // As wide as a fixed layer can be: the screen, less any space kept for a scroll bar (PhoneOverlaysTest).
        $page->assertScript('(() => { const probe = document.createElement("div"); probe.style.cssText = "position: fixed; inset: 0; visibility: hidden"; document.body.append(probe); const room = probe.getBoundingClientRect().width; probe.remove(); return Math.round(document.querySelector(\'[data-test="extension-add-sheet"]\').getBoundingClientRect().width) === Math.round(room); })()', true);
    }

    $page->click('[data-test="extension-ask"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-input"]\') !== null');
    shellSettled($page, '[data-slot="dialog-content"]');
    $page->type('[data-test="fresh-password-input"]', 'password')->click('[data-test="fresh-password-confirm"]');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="extension-request-status"]\')?.textContent === "Waiting for the installer"');
    shellSettled($page, '[data-test="extension-request-sheet"]');
    $page->assertSee('You can close this sheet: the installer carries on.')
        ->assertPresent('[data-test="extension-commands"]')
        ->assertNoAccessibilityIssues(3)
        ->assertScript($fits, true);

    expect(DB::table('extension_requests')->where('package', 'acme/link-suggestions')->value('constraint'))->toBe('^1.4.0');
})->with([1280, 375])->with(['light', 'dark']);

it('keeps the fields inside the sheet, and shows the form to add a Git address only when there is no saved source or one asks for it', function (): void {
    DB::table('extension_sources')->insert([
        'id' => strtolower((string) Illuminate\Support\Str::ulid()), 'kind' => 'git', 'label' => 'The maintainers’ repository with a long name',
        'url' => 'https://git.example.com/an/address/that/is/long/enough/to/stretch/a/select/past/the/sheets/edge.git',
        'username' => null, 'credential' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->admin)->get(route('plugins.index'))->assertOk();

    $page = cmSignIn($this->admin)->resize(1920, 1000)->navigate('/admin/plugins');
    $page->click('[data-test="plugins-add"]');
    shellSettled($page, '[data-test="extension-add-sheet"]');
    $page->click('[data-test="extension-tab-git"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"extension-tab-git\"]').dataset.state === 'active'");

    // Every field ends before the sheet does.
    $inside = <<<'JS'
(() => {
    const sheet = document.querySelector('[data-test="extension-add-sheet"]').getBoundingClientRect().right;
    return [...document.querySelectorAll('[data-test="extension-add-sheet"] [role="tabpanel"] :is(input, button[role="combobox"], select)')]
        .every((field) => field.getBoundingClientRect().right <= sheet - 16);
})()
JS;
    $page->click('#extension-git-source');
    cmWaitUntil($page, 'document.querySelector(\'[role="option"]\') !== null');
    $page->click('[role="option"] >> nth=-1');
    cmWaitUntil($page, 'document.querySelector("#extension-git-source").textContent.includes("long name")');
    $page->assertScript($inside, true)
        ->assertScript('document.querySelector("#extension-git-label") === null', true)
        ->assertScript('document.querySelector(\'[data-test="extension-git-add-another"]\') !== null', true);

    $page->click('[data-test="extension-git-add-another"]');
    cmWaitUntil($page, 'document.querySelector("#extension-git-label") !== null');
    $page->assertScript($inside, true);
});
