<?php

declare(strict_types=1);

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\UsageLimits;
use App\Domain\Settings\SecretStore;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Settings\SettingsReader;

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
    aiTask('fixture');
    aiTask('summary', [AiCapability::Text, AiCapability::StructuredOutput]);
    aiChoose('summary', 'fake:retired');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    actvRow($this->admin, ['task' => 'fixture']);
    cmSettings([UsageLimits::BUDGET => 50], AiSettings::KEY);

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('settings.ai'))->assertOk();
});

it('passes axe on Settings › AI, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->resize(1440, 900)
        ->navigate('/admin/settings/ai')
        ->assertSee('AI services')
        ->assertSee('Text you ask AI about is sent to Anthropic. The key is kept encrypted and never shown again.')
        ->assertSeeIn('[data-test="ai-key-state-anthropic"]', 'Not set')
        ->assertSee('No AI task is on yet.')
        ->assertSee('Fixture: model')
        ->assertSee('Chosen model (no longer offered)')
        ->assertSee('$0.0014')
        ->assertSee('Spent this month')
        ->assertNoAccessibilityIssues(3);

    // Model and fallback sit side by side at the same level on a wide screen.
    $page->assertScript(<<<'JS'
        (() => {
            const row = document.querySelector('[data-test="ai-task-fixture"]');
            if (row === null) {
                return false;
            }
            const style = getComputedStyle(row);
            return style.display === 'grid' && style.gridTemplateColumns.split(' ').length >= 2;
        })()
        JS);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('sets a key and a model once the password is confirmed, checks the key, and the task says it’s on (I-1308)', function (): void {
    Http::fake(['https://api.anthropic.com/v1/models' => Http::response(['data' => []])]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/ai')
        ->click('[data-test="ai-key-set-anthropic"]')
        ->type('#ai-key-anthropic', 'sk-ant-browser')
        ->click('#ai-model-fixture')
        ->click('[role="option"] >> text="Fake AI: Fake text"')
        // The list fades out as the choice lands; the next click would meet it.
        ->assertNotPresent('[role="listbox"]')
        ->assertSee('$1.00 a million tokens sent, $2.00 a million written.')
        ->click('[data-test="ai-settings-save"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    $page->type('[data-test="fresh-password-input"]', 'password')
        ->click('[data-test="fresh-password-confirm"]')
        ->assertSee('AI settings saved. Check key tells you whether the new key works.')
        ->assertSeeIn('[data-test="ai-key-state-anthropic"]', 'Saved')
        ->click('[data-test="ai-key-check-anthropic"]')
        ->assertSeeIn('[data-test="ai-key-check-result-anthropic"]', 'The key works.');

    // Summary's choice is no longer offered: it wasn't sent, so the save went through and left it stored.
    expect(app(SecretStore::class)->secret(AiSettings::KEY, 'anthropic_key'))->toBe('sk-ant-browser')
        ->and(app(AiAvailability::class)->taskReady('fixture'))->toBeTrue()
        ->and(app(SettingsReader::class)->string(AiSettings::KEY, 'summary_model'))->toBe('fake:retired');
});

it('removes a key only when saved, and Keep key takes the removal back (I-315)', function (): void {
    aiKey('anthropic_key', 'sk-ant-stored');

    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/ai')
        ->click('[data-test="ai-key-remove-anthropic"]')
        ->assertSee('The key will be removed when you save. Anthropic’s models stop working until it has a key again.')
        ->click('[data-test="ai-key-keep-anthropic"]')
        ->assertDontSee('The key will be removed when you save.')
        ->click('[data-test="ai-key-remove-anthropic"]')
        ->click('[data-test="ai-settings-save"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    $page->type('[data-test="fresh-password-input"]', 'password')
        ->click('[data-test="fresh-password-confirm"]')
        ->assertSee('AI settings saved.')
        ->assertSeeIn('[data-test="ai-key-state-anthropic"]', 'Not set')
        ->assertPresent('[data-test="ai-key-set-anthropic"]');

    expect(app(SecretStore::class)->secret(AiSettings::KEY, 'anthropic_key'))->toBeNull();
});

it('shows the server’s notice and nothing else while AI is switched off (I-1308)', function (): void {
    config(['teme.ai.enabled' => false]);

    cmSignIn($this->admin)
        ->navigate('/admin/settings/ai')
        ->assertSee('AI is switched off on this server.')
        ->assertPresent('[data-test="ai-off"]')
        ->assertNotPresent('[data-test="ai-settings-save"]')
        ->assertNotPresent('[data-test="ai-key-set-anthropic"]')
        ->assertDontSee('AI services')
        ->assertNoAccessibilityIssues(3);
});
