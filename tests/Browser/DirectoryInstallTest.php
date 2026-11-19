<?php

declare(strict_types=1);

use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Installing a plugin from Teme's directory, from the Add sheet.

require_once __DIR__.'/../Feature/Extensions/Admin/support.php';
require_once __DIR__.'/../Feature/Extensions/Sources/directory-support.php';

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
    extFolder();
    $keys = dirKeys();
    config(['teme.directory.public_keys' => [$keys['public']]]);
    dirServe(dirFiles($keys['secret'], [
        dirPackage('acme/links', listing: ['label' => 'Link suggestions', 'summary' => 'Suggests internal links.', 'picked' => true]),
        dirPackage('acme/quiet', 'theme', listing: ['label' => 'Quiet']),
    ]));
    $this->admin = User::factory()->create()->assignRole('administrator');

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('plugins.index'))->assertOk();
});

it('lists the directory’s plugins first on Add plugin, shows one’s permissions, and records its install, with axe, at each width and scheme', function (int $width, string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    $fits = 'document.documentElement.scrollWidth <= document.documentElement.clientWidth';

    $page = cmSignIn($this->admin)->resize($width, $width === 375 ? 812 : 900)->navigate('/admin/plugins');
    $page->click('[data-test="plugins-add"]');
    shellSettled($page, '[data-test="extension-add-sheet"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"extension-tab-directory\"]')?.dataset.state === 'active'");
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="directory-result"]\').length === 1');
    $page->assertSee('Link suggestions')
        ->assertSee('Teme’s pick')
        ->assertDontSee('Quiet')
        ->assertNoAccessibilityIssues(3)
        ->assertScript($fits, true);

    $page->click('[data-test="directory-choose"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="directory-permissions"]\') !== null');
    $page->assertSee('Read your posts, pages and other content, including drafts')
        ->assertSee('Connects to api.example.org')
        ->assertNoAccessibilityIssues(3)
        ->assertScript($fits, true);

    $page->click('[data-test="extension-ask"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-input"]\') !== null');
    shellSettled($page, '[data-slot="dialog-content"]');
    $page->type('[data-test="fresh-password-input"]', 'password')->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="extension-request-status"]\')?.textContent === "Waiting for the installer"');

    $row = DB::table('extension_requests')->where('package', 'acme/links')->first();
    expect($row->source_kind)->toBe('directory')->and($row->constraint)->toBe('^1.2.0');
})->with([1280, 375])->with(['light', 'dark']);

it('lists the directory’s themes on Add theme, apart from its plugins', function (): void {
    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/themes');
    $page->click('[data-test="themes-add"]');
    shellSettled($page, '[data-test="extension-add-sheet"]');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="directory-result"]\').length === 1');
    $page->assertSee('Quiet')->assertDontSee('Link suggestions')->assertNoAccessibilityIssues(3);
});

it('shows no Directory tab once the site switches the directory off', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('directory', ['enabled' => false], [], [], 0, (string) $this->admin->id));
    app()->forgetInstance(Elevation::class);

    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/plugins');
    $page->click('[data-test="plugins-add"]');
    shellSettled($page, '[data-test="extension-add-sheet"]');
    $page->assertMissing('[data-test="extension-tab-directory"]')
        ->assertScript("document.querySelector('[data-test=\"extension-tab-packagist\"]').dataset.state === 'active'", true);
});
