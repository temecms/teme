<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Teme\Contracts\Settings\SettingsReader;

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

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get('/admin/settings/general')->assertOk();
    $this->actingAs($this->admin)->get('/admin/settings/people')->assertOk();
});

it('shows one bar only once General changes, and saves the brand color with it, naming a refused color under its field', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/settings/general');
    cmWaitUntil($page, 'document.querySelector("#field-accent") !== null');

    $page->assertScript('document.querySelectorAll(\'[data-test="save-bar"]\').length === 1 && document.querySelector(\'[data-test="save-bar"]\').hidden')
        ->assertScript('document.querySelector(\'[data-test="brand-colour"] button[type="submit"]\') === null')
        ->type('#field-site_title', 'The Garden City Ledger')
        ->type('#field-accent', '#ffeb3b');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === false');

    $page->click('[data-test="settings-save"]');
    cmWaitUntil($page, 'document.querySelector("#field-accent-error")?.textContent.includes("The brand colour reaches only")');

    expect(app(SettingsReader::class)->string('general', 'site_title'))->not->toBe('The Garden City Ledger');

    $page->type('#field-accent', '#0b5394')->click('[data-test="settings-save"]');
    cmWaitUntil($page, 'document.body.textContent.includes("Settings saved.")');

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('The Garden City Ledger')
        ->and(app(SettingsReader::class)->string('design', 'accent'))->toBe('#0b5394');
});

it('asks before leaving with a change, keeps it on Keep editing, and Discard puts the page back', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/settings/general');
    cmWaitUntil($page, 'document.querySelector("#field-tagline") !== null');
    $before = $page->script('document.querySelector("#field-tagline").value');

    $page->type('#field-tagline', 'News from the harbour')
        ->click('nav[aria-label="Settings"] a[href="/admin/settings/writing"]');
    shellSettled($page, '[data-test="leave-guard"]');
    $page->click('[data-test="leave-guard-stay"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="leave-guard"]\') === null && location.pathname === "/admin/settings/general" && document.querySelector("#field-tagline").value === "News from the harbour"');

    $page->click('[data-test="save-bar-discard"]');
    cmWaitUntil($page, 'document.querySelector("#field-tagline").value === '.json_encode($before).' && document.querySelector(\'[data-test="save-bar"]\').hidden');
});

it('asks for the password in place on People, keeps the change, saves once confirmed, and does not ask again within the window', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/settings/people');
    cmWaitUntil($page, 'document.querySelector("#field-password_min_length") !== null');

    $page->type('#field-password_min_length', '16')->click('[data-test="settings-save"]');
    shellSettled($page, '[data-slot="dialog-content"]');
    $page->type('[data-test="fresh-password-input"]', 'not-the-password')->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-error"]\') !== null && document.querySelector(\'[data-test="fresh-password-input"]\').value === "not-the-password"');

    $page->type('[data-test="fresh-password-input"]', 'password')->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'document.body.textContent.includes("Settings saved.") && document.querySelector(\'[data-slot="dialog-content"]\') === null');
    expect(app(SettingsReader::class)->int('security', 'password_min_length'))->toBe(16);

    $page->type('#field-password_min_length', '17')->click('[data-test="settings-save"]');
    cmWaitUntil($page, 'document.querySelector("#field-password_min_length").value === "17" && document.querySelector(\'[data-test="save-bar"]\').hidden');
    expect(app(SettingsReader::class)->int('security', 'password_min_length'))->toBe(17);
});

it('pins the bar to the foot on a phone, with nothing scrolling sideways', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 812), '/admin/settings/general');
    cmWaitUntil($page, 'document.querySelector("#field-site_title") !== null');

    $page->type('#field-site_title', 'The Garden City Ledger');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === false');

    $page->assertScript('(() => { const bar = document.querySelector(\'[data-test="save-bar"]\').getBoundingClientRect(); return Math.abs(bar.bottom - window.innerHeight) <= 1 && bar.left <= 1; })()')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');
});

it('switches comments at once, and holds the switch while the sections below have unsaved changes', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/settings/discussion');
    cmWaitUntil($page, 'document.querySelector("#field-thread_depth") !== null');

    $page->assertScript('document.querySelector("#field-comments").getAttribute("role") === "switch"')
        ->type('#field-thread_depth', '4');
    cmWaitUntil($page, 'document.querySelector("#field-comments").disabled === true');

    $page->click('[data-test="save-bar-discard"]');
    cmWaitUntil($page, 'document.querySelector("#field-comments").disabled === false');

    $page->click('#field-comments');
    cmWaitUntil($page, 'document.body.textContent.includes("Comments switched off.")');
});
