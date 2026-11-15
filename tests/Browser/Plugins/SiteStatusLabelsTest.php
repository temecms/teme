<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Teme\Contracts\Status\StatusNotice;
use Tests\Fixtures\Plugins\Lists\NoticingPlugin;

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

    // Only the two checks below: core's own may have something to say on a test site.
    $checks = new StatusChecks;
    $checks->add(new readonly class implements StatusCheck
    {
        public function ability(): string
        {
            return 'settings.general.manage';
        }

        public function label(): string
        {
            return 'Testing';
        }

        public function banner(): ?StatusBanner
        {
            return new StatusBanner('warning', 'Something core checks wants a look.', null, null, 20);
        }
    }, 'core');
    app()->instance(StatusChecks::class, $checks);
    app(Contributions::class)->add('acme/noticing', 'noticing', 'Noticing', '1.0.0', [], new NoticingPlugin(new StatusNotice('warning', 'Noticing needs its token.')));

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin')->assertOk();
});

it('labels each site status row, a plugin’s with its mark, in both schemes at 1280 and 375', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->resize($width, 900);

    if ($width < 768) {
        cmWaitUntil($page, 'document.querySelector(\'[data-test="status-button"]\') !== null');
        $page->click('[data-test="status-button"]');
        shellSettled($page, '[data-test="status-sheet"]');
    } else {
        cmWaitUntil($page, 'document.querySelector(\'[data-test="site-status-button"]\')?.getAttribute("aria-label").startsWith("Site status: ") === true');
        $page->click('[data-test="site-status-button"]');
        shellSettled($page, '[data-test="site-status-panel"]');
    }

    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test="site-status-label"]\').length === 2');

    $rows = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="site-status-row"]')].map((row) => ({
            label: row.querySelector('[data-test="site-status-label"]')?.textContent ?? null,
            mark: row.querySelector('[data-slot="plugin-mark"] .sr-only')?.textContent ?? null,
        }))
        JS);

    expect($rows)->toBe([
        ['label' => 'Testing', 'mark' => null],
        ['label' => 'Noticing', 'mark' => 'From Noticing'],
    ]);

    // Opening the panel focuses its first stop, the plugin's mark, whose name shows in a tooltip; it closes when focus moves on.
    $page->script('() => document.activeElement?.blur()');
    cmWaitUntil($page, 'document.querySelector(\'[data-radix-popper-content-wrapper] [role="tooltip"]\') === null');

    expect(cmAxeViolations($page))->toBe([])
        // Still open: axe read the rows.
        ->and($page->script('() => document.querySelectorAll(\'[data-test="site-status-label"]\').length'))->toBe(2);
})->with(['light', 'dark'])->with([1280, 375]);
