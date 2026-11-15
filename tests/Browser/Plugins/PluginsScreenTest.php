<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
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

    $page->assertScript("document.querySelectorAll('[data-test=\"plugins-list\"] > li').length === 2");
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

    ($this->seen)($page, 'No plugins are installed');
    ($this->seen)($page, 'Whoever runs the server adds a plugin to the site’s build; it then appears here, disabled.');
    expect(cmAxeViolations($page))->toBe([]);
});

it('shows each plugin’s icon, its initials at 32 pixels, beside its name', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Installed, not enabled yet.');

    $icon = $page->script(<<<'JS'
        () => {
            const icons = document.querySelectorAll('[data-test="plugin-hello"] [data-slot="plugin-icon"]');
            const box = icons[0]?.getBoundingClientRect();

            return {
                count: icons.length,
                initials: icons[0]?.textContent ?? null,
                size: box ? [Math.round(box.width), Math.round(box.height)] : null,
                hidden: icons[0]?.getAttribute('aria-hidden') ?? null,
                rows: document.querySelectorAll('[data-test="plugins-list"] > li').length,
                everyRow: [...document.querySelectorAll('[data-test="plugins-list"] > li')].every((row) => row.querySelectorAll('[data-slot="plugin-icon"]').length === 1),
            };
        }
        JS);

    expect($icon)->toBe([
        'count' => 1,
        'initials' => 'H',
        'size' => [32, 32],
        'hidden' => 'true',
        'rows' => 2,
        'everyRow' => true,
    ]);
});

it('gives each state its pill and tone', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Review access for 1.1.0');

    $pills = $page->script(<<<'JS'
        () => Object.fromEntries(['hello', 'widened', 'gone'].map((code) => {
            const pill = document.querySelector(`[data-test="plugin-${code}"] [data-test="plugin-state"]`);

            return [code, [pill?.getAttribute('data-tone') ?? null, pill?.textContent ?? null]];
        }))
        JS);

    expect($pills)->toBe([
        'hello' => ['neutral', 'Disabled'],
        'widened' => ['warn', 'Needs attention'],
        'gone' => ['danger', 'Can’t be used'],
    ]);

    // The reason under a waiting plugin is in the warning's ink.
    $page->assertScript(<<<'JS'
        (() => {
            const probe = document.createElement('span');
            probe.className = 'text-warn-ink';
            document.body.append(probe);
            const ink = getComputedStyle(probe).color;
            probe.remove();

            return getComputedStyle(document.querySelector('[data-test="plugin-widened"] [data-test="plugin-reason"]')).color === ink;
        })()
        JS);
});

it('shows the plugin’s icon at 56 pixels in both dialogs', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Installed, not enabled yet.');

    $icon = <<<'JS'
        (() => {
            const icons = document.querySelectorAll('[role="dialog"] [data-slot="dialog-header"] [data-slot="plugin-icon"]');
            const box = icons[0]?.getBoundingClientRect();

            return icons.length === 1 && Math.round(box.width) === 56 && Math.round(box.height) === 56;
        })()
        JS;

    $page->click('[data-test="plugin-hello"] [data-test="plugin-enable"]');
    cmWaitUntil($page, $this->settled);
    $page->assertScript($icon)->keys('[role="dialog"]', 'Escape');
    cmWaitUntil($page, $this->closed);

    $page->click('[data-test="plugin-hello"] [data-test="plugin-menu"]')->click('[data-test="plugin-details"]');
    cmWaitUntil($page, $this->settled);
    $page->assertScript($icon);
});

it('moves between the views with the arrow keys', function (): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Review access for 1.1.0');

    $page->click('[data-test="plugins-tab-all"]');
    cmWaitUntil($page, "document.activeElement === document.querySelector('[data-test=\"plugins-tab-all\"]')");

    // Each press goes to whatever holds focus, so only the arrow keys can move it.
    foreach (['enabled', 'disabled'] as $next) {
        $page->page()->locator(':focus')->press('ArrowRight', ['delay' => 50]);
        cmWaitUntil($page, "document.activeElement === document.querySelector('[data-test=\"plugins-tab-{$next}\"]') && document.activeElement.getAttribute('aria-selected') === 'true'");
    }

    $page->assertScript("[...document.querySelectorAll('[role=\"tabpanel\"]:not([hidden]) [data-test=\"plugins-list\"] > li')].map((row) => row.getAttribute('data-test')).join('|') === 'plugin-hello'");
});

it('stacks each row at 375 and 320 pixels with nothing scrolling sideways, every target 24 pixels or more', function (int $width): void {
    ($this->site)();

    $page = cmSignIn($this->admin)->resize($width, 812)->navigate('/admin/plugins');
    ($this->seen)($page, 'Review access for 1.1.0');

    expect(($this->sideways)($page))->toBe(0)
        ->and($page->script(<<<'JS'
            () => [...document.querySelectorAll('[data-test="plugins-list"] [data-test="plugin-menu"], [data-test="plugins-list"] [data-test="plugin-enable"], [data-test="plugins-list"] [data-test="plugin-disable"]')]
                .every((button) => { const box = button.getBoundingClientRect(); return box.left >= 0 && box.right <= document.documentElement.clientWidth; })
            JS))->toBeTrue()
        ->and(cmSmallTargets($page)['small'])->toBe([]);
})->with([375, 320]);

it('runs database changes through the password screen for a plugin with waiting migrations', function (): void {
    $third = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_notes', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP;

    $copy = PluginFixtures::copy('tables', [
        'version' => '1.1.0',
        'owns' => ['tables' => ['tables_links', 'tables_clicks', 'tables_notes']],
    ]);
    PluginFixtures::use($copy);
    app()->instance(Elevation::class, new OperatorElevation);
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/tables',
        '1.1.0',
        ['content.read'],
        [],
        ['ContentPublished'],
        null,
    ));
    app()->forgetInstance(Elevation::class);

    file_put_contents($copy.'/database/migrations/2026_01_03_000000_create_tables_notes.php', $third);
    $manifest = json_decode((string) file_get_contents($copy.'/teme-plugin.json'), true);
    $manifest['version'] = '1.1.0';
    $manifest['owns'] = ['tables' => ['tables_links', 'tables_clicks', 'tables_notes']];
    file_put_contents($copy.'/teme-plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    app(PluginRegistry::class)->flush();

    $this->actingAs($this->admin)->get('/admin/plugins')->assertOk();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Version 1.1.0 has database changes to run.');
    $page->assertScript("document.querySelector('[data-test=\"plugin-tables\"] [data-test=\"plugin-migrate\"]')?.textContent?.includes('Run database changes') === true");

    $page->click('[data-test="plugin-tables"] [data-test="plugin-migrate"]');
    cmWaitUntil($page, "location.pathname === '/admin/user/confirm-password'");
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Tables’s database changes ran.');

    expect(Schema::hasTable('tables_notes'))->toBeTrue();
    $page->assertScript("document.querySelector('[data-test=\"plugin-tables\"] [data-test=\"plugin-state\"]')?.textContent === 'Enabled'");
});

it('offers Remove its data for a disabled plugin with tables, and removes them after confirmation', function (): void {
    PluginFixtures::use('tables');
    app()->instance(Elevation::class, new OperatorElevation);
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/tables',
        '1.0.0',
        ['content.read'],
        [],
        ['ContentPublished'],
        null,
    ));
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null));
    app()->forgetInstance(Elevation::class);

    $this->actingAs($this->admin)->get('/admin/plugins')->assertOk();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Tables');

    $page->click('[data-test="plugin-tables"] [data-test="plugin-menu"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-remove-data\"]') !== null");
    $page->click('[data-test="plugin-remove-data"]');
    cmWaitUntil($page, $this->settled);

    $page->assertScript("document.querySelector('[data-test=\"remove-data-dialog\"] h2')?.textContent === 'Remove Tables’s data?'")
        ->assertScript("document.querySelector('[data-test=\"remove-data-tables\"]').innerText.includes('tables_links')")
        ->assertScript("document.querySelector('[data-test=\"remove-data-tables\"]').innerText.includes('tables_clicks')");

    $page->click('[data-test="remove-data-confirm"]');
    cmWaitUntil($page, "location.pathname === '/admin/user/confirm-password'");
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'Tables’s data was removed');

    expect(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('tables_clicks'))->toBeFalse();
});

it('offers Remove its data for a code-gone plugin with tables', function (): void {
    $copy = PluginFixtures::copy('tables');
    PluginFixtures::use($copy);
    app()->instance(Elevation::class, new OperatorElevation);
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/tables',
        '1.0.0',
        ['content.read'],
        [],
        ['ContentPublished'],
        null,
    ));
    app()->forgetInstance(Elevation::class);

    (new Filesystem)->deleteDirectory($copy);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();

    $this->actingAs($this->admin)->get('/admin/plugins')->assertOk();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Its code is no longer installed');

    $page->click('[data-test="plugin-tables"] [data-test="plugin-menu"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-remove-data\"]') !== null");
    $page->click('[data-test="plugin-remove-data"]');
    cmWaitUntil($page, $this->settled);
    $page->click('[data-test="remove-data-confirm"]');
    cmWaitUntil($page, "location.pathname === '/admin/user/confirm-password'");
    $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
    ($this->seen)($page, 'data was removed');

    expect(Schema::hasTable('tables_links'))->toBeFalse();
});

it('does not offer Remove its data while the plugin is enabled', function (): void {
    PluginFixtures::use('tables');
    app()->instance(Elevation::class, new OperatorElevation);
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/tables',
        '1.0.0',
        ['content.read'],
        [],
        ['ContentPublished'],
        null,
    ));
    app()->forgetInstance(Elevation::class);

    $this->actingAs($this->admin)->get('/admin/plugins')->assertOk();

    $page = cmSignIn($this->admin)->navigate('/admin/plugins');
    ($this->seen)($page, 'Tables');

    $page->click('[data-test="plugin-tables"] [data-test="plugin-menu"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-details\"]') !== null");
    $page->assertScript("document.querySelector('[data-test=\"plugin-remove-data\"]') === null");
});
