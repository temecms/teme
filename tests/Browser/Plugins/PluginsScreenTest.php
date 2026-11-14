<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Plugins\PluginFixtures;

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

    app()->forgetInstance(Elevation::class);
    PluginFixtures::use('hello', 'widened');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');
    // How far the page scrolls sideways: none. The kept scrollbar gutter makes the raw difference negative.
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');
    // The dialog's own animations, never its subtree's (.ai/rules/builders.md).
    $this->settled = 'document.querySelector(\'[role="dialog"]\') !== null && document.querySelector(\'[role="dialog"]\').getAnimations().every((a) => a.playState !== "running")';
    $this->closed = 'document.querySelector(\'[role="dialog"]\') === null';

    // A site with every kind of row: enabled, never enabled, waiting for more access, code gone.
    $this->site = function (): void {
        PluginFixtures::use('hello', PluginFixtures::copy('widened', ['version' => '1.1.0', 'capabilities' => ['content.read', 'http', 'media.read']]));
        PluginRecord::factory()->enabled()->create(['name' => 'tests/widened', 'code' => 'widened', 'consent' => ['capabilities' => ['content.read', 'http'], 'hosts' => ['api.example.com'], 'hooks' => ['ContentPublished']]]);
        PluginRecord::factory()->enabled()->create(['name' => 'tests/gone', 'code' => 'gone']);
    };

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/plugins')->assertOk();
});

afterEach(fn () => PluginFixtures::forget());

it('draws the adopted design with no accessibility issue, in both schemes and at both widths', function (string $scheme): void {
    ($this->site)();
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/plugins');
    ($this->seen)($page, 'Review access for 1.1.0');

    expect(cmAxeViolations($page))->toBe([])
        ->and(cmSmallTargets($page)['small'])->toBe([]);
    $page->screenshot(true, "plugins-list-{$scheme}-1280");

    // Details and access.
    $page->click('[data-test="plugin-hello"] [data-test="plugin-menu"]')->click('[data-test="plugin-details"]');
    cmWaitUntil($page, $this->settled);
    expect(cmAxeViolations($page))->toBe([]);
    $page->screenshot(true, "plugins-details-{$scheme}-1280")->keys('[role="dialog"]', 'Escape');
    cmWaitUntil($page, $this->closed);

    // The consent dialog of an update that asks for more.
    $page->click('[data-test="plugin-widened"] [data-test="plugin-enable"]');
    cmWaitUntil($page, $this->settled);
    expect(cmAxeViolations($page))->toBe([]);
    $page->screenshot(true, "plugins-dialog-{$scheme}-1280")->keys('[role="dialog"]', 'Escape');
    cmWaitUntil($page, $this->closed);

    // At 375 pixels the table is stacked rows, with nothing scrolling sideways.
    $page->resize(375, 812)->navigate('/admin/plugins');
    ($this->seen)($page, 'Review access for 1.1.0');
    expect(($this->sideways)($page))->toBe(0)
        ->and(cmAxeViolations($page))->toBe([]);
    $page->screenshot(true, "plugins-list-{$scheme}-375");

    $page->click('[data-test="plugin-hello"] [data-test="plugin-menu"]')->click('[data-test="plugin-details"]');
    cmWaitUntil($page, $this->settled);
    expect(($this->sideways)($page))->toBe(0);
    $page->screenshot(true, "plugins-details-{$scheme}-375")->keys('[role="dialog"]', 'Escape');
    cmWaitUntil($page, $this->closed);

    $page->click('[data-test="plugin-widened"] [data-test="plugin-enable"]');
    cmWaitUntil($page, $this->settled);
    expect(($this->sideways)($page))->toBe(0);
    $page->screenshot(true, "plugins-dialog-{$scheme}-375");
})->with(['light', 'dark']);

it('opens the consent dialog, lists what it asks to, and enables the plugin after the password screen', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Installed, not enabled yet.');

    $page->click('[data-test="plugin-hello"] [data-test="plugin-enable"]');
    cmWaitUntil($page, $this->settled);

    $page->assertScript("document.querySelector('[data-test=\"consent-dialog\"] h2')?.textContent === 'Enable Hello 1.0.0?'")
        ->assertScript("document.querySelector('[data-test=\"consent-dialog\"]').innerText.includes('Plugins are trusted code.')")
        ->assertScript("document.querySelector('[data-test=\"consent-asks\"]').innerText === 'Read your posts, pages and other content, including drafts'")
        ->assertScript("document.querySelector('[data-test=\"consent-confirm\"]').textContent === 'Agree and enable 1.0.0'")
        // No password box in the dialog (I-3342).
        ->assertScript("document.querySelector('[data-test=\"consent-dialog\"] input') === null");

    // The password wasn't confirmed lately: Teme's own screen, then back here with the plugin enabled.
    $page->click('[data-test="consent-confirm"]');
    cmWaitUntil($page, "location.pathname === '/admin/user/confirm-password'");
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Hello enabled. Its contributions appear where they belong.');

    expect(PluginRecord::query()->where('name', 'tests/hello')->value('state'))->toBe('enabled');
    $page->assertScript("document.querySelector('[data-test=\"plugin-hello\"] [data-test=\"plugin-state\"]')?.textContent === 'Enabled'");

    // One click disables it again.
    $page->click('[data-test="plugin-hello"] [data-test="plugin-disable"]');
    ($this->seen)($page, 'Hello disabled. Everything it added to the admin is gone until you enable it; its data is kept.');
    expect(PluginRecord::query()->where('name', 'tests/hello')->value('state'))->toBe('disabled');
});

it('closes the dialog with Escape and returns focus to the button that opened it', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Installed, not enabled yet.');

    $page->click('[data-test="plugin-hello"] [data-test="plugin-enable"]');
    cmWaitUntil($page, $this->settled);
    $page->keys('[role="dialog"]', 'Escape');
    cmWaitUntil($page, $this->closed);

    cmWaitUntil($page, "document.activeElement === document.querySelector('[data-test=\"plugin-hello\"] [data-test=\"plugin-enable\"]')");

    // Details and access, opened from the ⋯ menu, gives focus back to the ⋯ button.
    $page->click('[data-test="plugin-hello"] [data-test="plugin-menu"]')->click('[data-test="plugin-details"]');
    cmWaitUntil($page, $this->settled);
    $page->keys('[role="dialog"]', 'Escape');
    cmWaitUntil($page, $this->closed);
    cmWaitUntil($page, "document.activeElement === document.querySelector('[data-test=\"plugin-hello\"] [data-test=\"plugin-menu\"]')");
});

it('filters by tab with each count, and the ⋯ button has a name', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Review access for 1.1.0');

    $page->assertScript("[...document.querySelectorAll('[role=tab]')].map((tab) => tab.textContent).join('|') === 'All3|Enabled0|Disabled1|Needs attention2'")
        ->assertScript("document.querySelector('[data-test=\"plugin-hello\"] [data-test=\"plugin-menu\"]').getAttribute('aria-label') === 'Actions for Hello'")
        ->click('[data-test="plugins-tab-attention"]');

    $page->assertScript("document.querySelectorAll('[data-test=\"plugins-table\"] tbody tr').length === 2");
});

it('shows the reason under a plugin that can’t be used', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Its code is no longer installed. Its settings are kept.');

    $page->assertScript("document.querySelector('[data-test=\"plugin-gone\"] [data-test=\"plugin-state\"]')?.textContent === 'Can’t be used'")
        ->assertScript("document.querySelector('[data-test=\"plugin-widened\"] [data-test=\"plugin-reason\"]')?.textContent === 'Update 1.1.0 is blocked: it asks for more access than you agreed to.'");
});

it('shows the empty state', function (): void {
    config(['teme.plugin_paths' => []]);

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');

    ($this->seen)($page, 'No plugins are installed. Whoever runs the server adds a plugin to the site’s build; it then appears here, disabled.');
    expect(cmAxeViolations($page))->toBe([]);
});
