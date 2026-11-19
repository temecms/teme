<?php

declare(strict_types=1);

use App\Domain\ManageApi\ManageApiSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\ManageApi\ManageApiFixture;

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
    $this->writer = User::factory()->create(['name' => 'Wyn Writer'])->assignRole('author');

    // Signs Ada in and confirms her password first, as making a key asks (I-1091).
    $this->signInConfirmed = fn (): mixed => cmSignIn($this->admin)
        ->navigate(route('password.confirm', [], false))
        ->type('#password', 'password')
        ->click('[data-test="confirm-password-button"]')
        ->assertPathIs('/admin');

    // The plugin's assertSee() looks once and answers at once (issue #83).
    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text, JSON_THROW_ON_ERROR).')');

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('settings.management-api'))->assertOk();
});

it('turns the API on, makes a key whose secret is shown once with Copy, and revokes it', function (): void {
    $page = ($this->signInConfirmed)()->navigate('/admin/settings/management_api');
    ($this->seen)($page, 'Tokens');

    $page->click('[data-test="management-api-switch"]');
    ($this->seen)($page, 'The management API is on. Outside tools can sign in with a token.');

    expect(ManageApiSettings::on())->toBeTrue();

    $page->click('[data-test="api-key-add"]');
    shellSettled($page, '[data-test="api-key-dialog"]')
        ->type('#api-key-name', 'Importer')
        ->click('#api-key-person')
        ->click('[role="option"] >> text="Wyn Writer"');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null");

    $page->click('#api-ability-site\\.read')
        ->click('#api-ability-content\\.write')
        ->click('[data-test="api-key-create"]');

    cmWaitUntil($page, "document.querySelector('[data-test=\"api-key-secret\"]') !== null");
    $secret = $page->script("document.querySelector('[data-test=\"api-key-secret\"]').value");

    expect($secret)->toMatch('/^teme_[0-9A-Za-z]{40}$/')
        ->and(DB::table('api_keys')->value('token_hash'))->toBe(hash('sha256', $secret));

    // Headless Chromium has no clipboard to grant; a stand-in keeps what Copy writes.
    $page->script('navigator.clipboard.writeText = (text) => { window.__copied = text; return Promise.resolve(); }');
    $page->click('[data-test="api-key-copy"]');
    ($this->seen)($page, 'Copied');
    $page->assertScript('window.__copied', $secret)
        ->assertSee('You won’t see this again.')
        ->click('[data-test="api-key-done"]');

    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') === null");
    ($this->seen)($page, 'Importer');
    $page->assertScript('document.body.innerText.includes('.json_encode($secret, JSON_THROW_ON_ERROR).')', false)
        ->click('[data-test="api-key-revoke"]');

    ($this->seen)($page, 'Revoke “Importer”?');
    $page->click('[role="dialog"] >> text="Revoke token"');
    ($this->seen)($page, 'Token revoked. Tools using it are refused from now on.');

    expect(DB::table('api_keys')->whereNotNull('revoked_at')->count())->toBe(1);
});

it('passes axe on Settings › Management API, every target 24 pixels or more, at 1280 and 375 pixels, in both schemes', function (string $scheme, int $width): void {
    ManageApiFixture::key($this->writer, ['site.read'], 'Importer');
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin)->resize($width, $width === 375 ? 812 : 900), '/admin/settings/management_api');
    ($this->seen)($page, 'Importer');

    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark'])->with([1280, 375]);
