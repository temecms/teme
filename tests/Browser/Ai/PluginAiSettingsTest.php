<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Ai\UsageLimits;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/../../Feature/Ai/Plugins/helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    Http::preventStrayRequests();
    aiFake();
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    scribeEnable();
    scribeChoose('article', 'fake:fake-text');
    cmSettings([UsageLimits::BUDGET => 50, PluginAiSection::budgetKey('scribe') => 5], AiSettings::KEY);

    // One request Scribe made this month, recorded as the door records it.
    actvRow($this->admin, ['task' => 'article', 'subjectType' => AiSubjectType::None, 'subjectId' => null, 'outcome' => AiOutcome::Answered, 'variant' => null, 'plugin' => 'scribe', 'usage' => new AiUsage(input: 1_000, output: 50), 'costMicrounits' => 1_100]);

    // How far the page scrolls sideways: none. The kept scrollbar gutter makes the raw difference negative.
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('settings.ai'))->assertOk();
    $this->actingAs($this->admin)->get('/admin/tools/ai-activity')->assertOk();
});

afterEach(fn () => PluginFixtures::forget());

it('draws a plugin’s own panel on Settings › AI, its name once, with no accessibility issue', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/settings/ai')
        ->assertSeeIn('[data-test="ai-plugin-month-scribe"]', '$0.0011, 1 request')
        ->assertSeeIn('[data-test="ai-plugin-month-scribe"]', '$5.00 a month')
        ->assertSeeIn('[data-test="ai-task-scribe-article"]', 'Sends only what the plugin writes itself.')
        ->assertSeeIn('[data-test="ai-task-status-scribe-article"]', 'On')
        ->assertSeeIn('[data-test="ai-task-status-scribe-titles"]', 'Off: Suggest titles is off until an administrator chooses its model in Settings › AI.')
        ->assertSee('Monthly budget, in US dollars');

    // The plugin's name is said once, in the panel's header, never again inside it.
    expect($page->script('() => document.querySelector(\'[data-test="ai-plugin-scribe"]\').innerText.includes("Scribe")'))->toBeFalse()
        ->and(cmAxeViolations($page))->toBe([])
        ->and(cmSmallTargets($page)['small'])->toBe([])
        ->and(($this->sideways)($page))->toBe(0);
})->with(['light', 'dark'])->with([1280, 375]);

it('switches AI off for a plugin once the password is confirmed', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/settings/ai')
        ->click('#field-'.PluginAiSection::onKey('scribe'))
        ->click('[data-test="ai-settings-save"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    $page->type('[data-test="fresh-password-input"]', 'password')
        ->click('[data-test="fresh-password-confirm"]')
        ->assertSee('AI settings saved.')
        ->assertSeeIn('[data-test="ai-task-status-scribe-article"]', 'Off: AI is switched off for this plugin in Settings › AI.');

    expect(app(SettingsReader::class)->bool(AiSettings::KEY, PluginAiSection::onKey('scribe')))->toBeFalse();
});

it('names the plugin in Tools › AI activity, totals it, and filters by it, with no accessibility issue', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin), '/admin/tools/ai-activity')
        ->assertSeeIn('[data-test="ai-activity-plugin-month-scribe"]', 'Scribe')
        ->assertSeeIn('[data-test="ai-activity-plugin-month-scribe"]', '$0.0011, 1 request')
        ->assertSee('Write an article')
        ->assertSee('No item');

    expect(cmAxeViolations($page))->toBe([]);

    $page->click('#ai-plugin')
        ->click('[role="option"] >> text="Scribe"')
        ->assertNotPresent('[role="listbox"]')
        ->click('[data-test="ai-filter"]');
    cmWaitUntil($page, 'window.location.search.includes("plugin=scribe")');

    $page->assertSee('Write an article')
        ->assertSee('Clear filters');
})->with(['light', 'dark']);

it('shows the plugin’s AI cost this month on the Plugins screen', function (): void {
    cmNavigate(cmSignIn($this->admin), '/admin/plugins')
        ->assertSeeIn('[data-test="plugin-scribe"] [data-test="plugin-ai"]', 'AI this month: $0.0011 of $5.00');
});
