<?php

declare(strict_types=1);

use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

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

    // Waits for words on the page; the plugin's assertSee() looks once (issue #83).
    $this->textShown = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text, JSON_THROW_ON_ERROR).')');

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('settings.site-code'))->assertOk();
});

it('passes axe on Settings › Site code, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    scAdd($this->admin, 'Visitor analytics');
    scAdd($this->admin, 'Organization', 'head', '<script type="application/ld+json">{"@type":"Organization"}</script>', on: false);
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/settings/site-code');
    ($this->textShown)($page, 'added “Organization”');

    $page->assertSee('Code here runs in every reader’s browser.')
        ->assertSee('This code holds structured data.')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('saves code with a wildcard host when the password is fresh', function (): void {
    $code = '<script data-test="pasted">window.a = 1 && 2;</script>';
    $page = cmSignIn($this->admin)
        ->navigate(route('password.confirm', [], false))
        ->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]');
    cmWaitUntil($page, "location.pathname === '/admin'");

    $page->navigate('/admin/settings/site-code')
        ->click('[data-test="site-code-add"]')
        ->type('#site-code-name', 'Visitor analytics')
        ->type('#site-code-code', $code)
        ->type('#site-code-origins', '*.example.org')
        ->click('[data-test="site-code-save"]');

    ($this->textShown)($page, 'Code added. Every public page includes “Visitor analytics” in the head.');

    expect(SiteCodeEntry::query()->sole()->origins)->toBe(['*.example.org']);
});

it('asks for a lapsed password in place and keeps the wildcard host', function (): void {
    $code = '<script data-test="pasted">window.a = 1 && 2;</script>';
    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/site-code')
        ->click('[data-test="site-code-add"]')
        ->type('#site-code-name', 'Visitor analytics')
        ->type('#site-code-code', $code)
        ->type('#site-code-origins', '*.example.org')
        ->click('[data-test="site-code-save"]');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-input"]\') !== null');
    $page->type('[data-test="fresh-password-input"]', 'password')
        ->click('[data-test="fresh-password-confirm"]');
    ($this->textShown)($page, 'Code added. Every public page includes “Visitor analytics” in the head.');

    expect(SiteCodeEntry::query()->sole()->origins)->toBe(['*.example.org']);
});

it('adds code once the password is confirmed, switches it off and deletes it, each step saying so (I-864)', function (): void {
    $code = '<script data-test="pasted">window.a = 1 && 2;</script>';

    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/site-code')
        ->click('[data-test="site-code-add"]')
        ->type('#site-code-name', 'Visitor analytics')
        ->type('#site-code-code', $code)
        ->click('[data-test="site-code-save"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-input"]\') !== null');
    $page->type('[data-test="fresh-password-input"]', 'password')
        ->click('[data-test="fresh-password-confirm"]');

    ($this->textShown)($page, 'Code added. Every public page includes “Visitor analytics” in the head.');

    $page->click('[data-test="site-code-switch"]');
    ($this->textShown)($page, 'Switched off. No page includes “Visitor analytics” until it’s on again.');

    $page->click('[data-test="site-code-delete"]')
        ->press('Delete code');
    ($this->textShown)($page, 'Code deleted. No page includes “Visitor analytics” any more.');
    ($this->textShown)($page, 'No code added yet');

    expect(SiteCodeEntry::query()->count())->toBe(0)
        ->and(array_column(scLog(), 'type'))->toBe(['site_code.added', 'site_code.switched_off', 'site_code.deleted'])
        ->and(scLog()[0]['context']['after_code'])->toBe($code);
});

it('saves the hosts the code also connects to, shows what it found, and shows a refused line on its box (I-3375)', function (): void {
    $code = '<script defer data-site="ledger" src="https://stats.example.org/script.js"></script>';
    $add = fn (mixed $page): mixed => $page->click('[data-test="site-code-add"]')
        ->type('#site-code-name', 'Visitor analytics')
        ->type('#site-code-code', $code)
        ->type('#site-code-origins', 'plausible.io');

    $page = cmSignIn($this->admin)->navigate('/admin/settings/site-code');
    $add($page)
        ->assertNotPresent('[data-test="site-code-found"]')
        ->click('[data-test="site-code-save"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-input"]\') !== null');
    $page->type('[data-test="fresh-password-input"]', 'password')
        ->click('[data-test="fresh-password-confirm"]');
    ($this->textShown)($page, 'Code added. Every public page includes “Visitor analytics” in the head.');

    // The panel has closed after saving before Edit opens it again.
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null')->click('[data-test="site-code-edit"]');
    ($this->textShown)($page, 'Found in the code: https://stats.example.org.');

    $page->assertValue('#site-code-origins', 'plausible.io')
        ->assertSeeIn('[data-test="site-code-found"] code', 'https://stats.example.org')
        ->assertAttributeContains('#site-code-origins', 'aria-describedby', 'site-code-found')
        ->assertNoAccessibilityIssues(3);

    $page->clear('#site-code-origins')
        ->type('#site-code-origins', 'https://plausible.io')
        ->click('[data-test="site-code-save"]');
    ($this->textShown)($page, '“https://plausible.io” isn\'t a host name.');

    $page->assertSeeIn('#site-code-origins-error', '“https://plausible.io” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.')
        ->assertAttribute('#site-code-origins', 'aria-invalid', 'true');

    expect(SiteCodeEntry::query()->sole()->origins)->toBe(['plausible.io']);
});

it('shows the host\'s notice and no way to change anything while the host disallows site code (I-866)', function (): void {
    scAdd($this->admin, 'Visitor analytics');
    config(['teme.disallow_site_code' => true]);

    $page = cmSignIn($this->admin)->navigate('/admin/settings/site-code');
    ($this->textShown)($page, 'On, but switched off by the host');

    $page->assertSee('The host has switched site code off.')
        ->assertNotPresent('[data-test="site-code-add"]')
        ->assertNotPresent('[data-test="site-code-edit"]')
        ->assertNotPresent('[data-test="site-code-switch"]');
});
