<?php

declare(strict_types=1);

use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
    $this->actingAs($this->admin)->get(route('preferences.edit'))->assertOk();
});

it('applies a look as it is chosen, before any Save, and keeps it', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/account/preferences')
        // Dark chrome, the default, is the stylesheet's own and needs no attribute.
        ->assertScript('document.documentElement.dataset.look === undefined')
        ->click('[name="appearance-look"][value="quiet"]')
        ->assertScript('document.documentElement.dataset.look', 'quiet')
        // Not a wait for the save: networkidle has passed once the page has loaded. The save went out
        // before the next visit, and the test's server answers requests in the order they arrive.
        ->waitForEvent('networkidle')
        ->navigate('/admin')
        ->assertScript('document.documentElement.dataset.look', 'quiet');

    expect(PreferencesFor::user($this->admin->refresh())['appearance.look'])->toBe('quiet');
});

it('switches high contrast on as it is ticked, before any Save, and keeps it', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/account/preferences')
        ->assertScript('document.documentElement.dataset.contrast === undefined')
        ->click('#appearance-high-contrast')
        ->assertScript('document.documentElement.dataset.contrast', 'high')
        // Not a wait for the save, as in the test above: the order of the requests keeps it.
        ->waitForEvent('networkidle')
        ->navigate('/admin')
        ->assertScript('document.documentElement.dataset.contrast', 'high');

    expect(PreferencesFor::user($this->admin->refresh())['appearance.high_contrast'])->toBeTrue();
});

it('puts the look back and says so when the server refuses to save it', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/account/preferences');

    // This page's background saves go to an address that answers with an error, as a lost session's would.
    $page->script(<<<'JS'
        () => {
            const open = XMLHttpRequest.prototype.open;

            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                const refused = String(method).toUpperCase() === 'PATCH' && String(url).includes('/admin/account/preferences');

                return open.call(this, method, refused ? String(url).replace('/admin/account/preferences', '/admin/account/preferences-refused') : url, ...rest);
            };
        }
        JS);

    $page->click('[name="appearance-look"][value="quiet"]')
        ->assertSee('Your choice couldn’t be saved, so the admin is back as it was. Try again in a moment.')
        ->assertNoAccessibilityIssues(3)
        ->assertScript('document.documentElement.dataset.look === undefined')
        ->assertScript('document.querySelector(\'[name="appearance-look"][value="dark_chrome"]\').checked');

    expect(PreferencesFor::user($this->admin->refresh())['appearance.look'])->toBe('dark_chrome');
});
