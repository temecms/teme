<?php

declare(strict_types=1);

use App\Domain\Ai\AiSettings;
use App\Domain\Ai\UsageLimits;
use App\Domain\Settings\SecretStore;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->save = fn (array $body, bool $confirmed = true) => ($confirmed ? $this->actingAs($this->admin)->withSession(scConfirmed()) : $this->actingAs($this->admin))
        ->from('/admin/settings/ai')
        ->put('/admin/settings/ai', $body);
});

it('draws Settings › AI at the group’s own address, for administrators alone (I-312, I-1308)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->get('/admin/settings/ai')->assertRedirect('/admin/login');

    $this->actingAs($this->admin)->get('/admin/settings/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('enabled', true)
            ->where('version', 0)
            ->has('sections')
            ->has('month'));

    expect(route('settings.ai', [], false))->toBe('/admin/settings/ai')
        ->and(route('settings.show', ['group' => AiSettings::KEY], false))->toBe('/admin/settings/ai');

    $this->actingAs($editor)->get('/admin/settings/ai')->assertForbidden();
    $this->actingAs($editor)->withSession(scConfirmed())
        ->put('/admin/settings/ai', ['values' => [UsageLimits::HOURLY => 5], 'expected_version' => 0])
        ->assertForbidden();

    expect(DB::table('settings')->where('group_key', AiSettings::KEY)->exists())->toBeFalse();
});

it('asks for the password before it saves anything, then saves through UpdateSettings (I-1308)', function (): void {
    ($this->save)(['values' => [UsageLimits::HOURLY => 30], 'expected_version' => 0], false)
        ->assertRedirect(route('password.confirm'));

    expect(UsageLimits::hourly())->toBe(60);

    ($this->save)(['values' => [UsageLimits::HOURLY => 30, UsageLimits::BUDGET => 25], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/ai')
        ->assertInertiaFlash('toast.message', 'AI settings saved.');

    expect(UsageLimits::hourly())->toBe(30)
        ->and(UsageLimits::budgetMicrounits())->toBe(25_000_000);
});

it('saves a key without the spaces around it, says Check key can try it, and never shows it (I-315)', function (): void {
    ($this->save)(['secrets' => ['anthropic_key' => "  sk-ant-pasted \n"], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/ai')
        ->assertInertiaFlash('toast.message', 'AI settings saved. Check key tells you whether the new key works.');

    expect(app(SecretStore::class)->secret(AiSettings::KEY, 'anthropic_key'))->toBe('sk-ant-pasted');

    $page = $this->actingAs($this->admin)->get('/admin/settings/ai')->assertOk();

    expect((string) $page->getContent())->not->toContain('sk-ant-pasted')
        ->and(aisSection($page->inertiaProps(), 'service', 'anthropic')['state'])->toBe('set');
});

it('leaves a key as it is when its field comes back empty, and removes it when asked (I-315)', function (): void {
    aiKey('anthropic_key', 'sk-ant-kept');

    ($this->save)(['secrets' => ['anthropic_key' => ''], 'values' => [UsageLimits::HOURLY => 40], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'AI settings saved.');

    expect(app(SecretStore::class)->secret(AiSettings::KEY, 'anthropic_key'))->toBe('sk-ant-kept');

    ($this->save)(['clear_secrets' => ['anthropic_key'], 'expected_version' => 1])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'AI settings saved.');

    expect(app(SecretStore::class)->secret(AiSettings::KEY, 'anthropic_key'))->toBeNull();
});

it('keeps a typed key out of the session when a save is refused, and says why under the select (I-315)', function (): void {
    aiFake();
    aiTask('fixture');

    ($this->save)(['secrets' => ['anthropic_key' => 'sk-ant-typed'], 'values' => ['fixture_model' => 'fake:retired'], 'expected_version' => 0])
        ->assertSessionHasErrors(['values.fixture_model' => 'Fake AI has no model called retired.']);

    expect(session()->getOldInput())->not->toHaveKey('secrets')
        ->and(session()->getOldInput('values.fixture_model'))->toBe('fake:retired')
        ->and((string) json_encode(session()->all()))->not->toContain('sk-ant-typed')
        ->and(app(SecretStore::class)->secret(AiSettings::KEY, 'anthropic_key'))->toBeNull();
});

it('refuses to change a key the server sets (I-315)', function (): void {
    aiServerKey('TEME_AI_ANTHROPIC_KEY', 'sk-ant-server');

    try {
        ($this->save)(['secrets' => ['anthropic_key' => 'sk-ant-typed'], 'expected_version' => 0])
            ->assertSessionHasErrors(['secrets.anthropic_key' => 'This value is provided by the server and cannot be changed here.']);

        expect(app(SecretStore::class)->secret(AiSettings::KEY, 'anthropic_key'))->toBe('sk-ant-server');
    } finally {
        aiServerKey('TEME_AI_ANTHROPIC_KEY', null);
    }
});

it('saves a model and its fallback as the selects send them, and the task is then on (I-1308)', function (): void {
    aiFake();
    aiTask('fixture');

    ($this->save)(['values' => ['fixture_model' => 'fake:fake-text', 'fixture_fallback' => 'fake:fake-unpriced'], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'AI settings saved.');

    $task = aisTask($this->actingAs($this->admin)->get('/admin/settings/ai')->inertiaProps(), 'fixture');

    expect($task['on'])->toBeTrue()
        ->and($task['status'])->toBe('On');
});

it('refuses a save made on an older version of the settings', function (): void {
    ($this->save)(['values' => [UsageLimits::HOURLY => 30], 'expected_version' => 0])->assertSessionHasNoErrors();

    ($this->save)(['values' => [UsageLimits::HOURLY => 20], 'expected_version' => 0])
        ->assertSessionHasErrors(['expected_version' => 'These settings were changed by someone else while you had them open. Reload the page and try again.']);

    expect(UsageLimits::hourly())->toBe(30);
});

it('shows only the switch and changes nothing while the server has AI switched off (I-1308)', function (): void {
    config(['teme.ai.enabled' => false]);

    $this->actingAs($this->admin)->get('/admin/settings/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('enabled', false)
            ->missing('sections')
            ->missing('values'));

    ($this->save)(['values' => [UsageLimits::HOURLY => 30], 'expected_version' => 0])->assertForbidden();

    expect(DB::table('settings')->where('group_key', AiSettings::KEY)->exists())->toBeFalse();
});
