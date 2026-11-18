<?php

declare(strict_types=1);

use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * Settings › Site code's "Scripts from plugins" (docs/plans/plugin-sockets-2.md,
 * B4): every inline script a plugin asked to keep, waiting, approved or
 * refused, decided there by someone who may change site code, with the
 * password asked again, through the plugin script Operations.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');

    // A plugin's script as RequestPluginScript leaves it.
    $this->script = fn (string $state = PluginScript::WAITING, string $script = 'window.ads = 1;'): PluginScript => PluginScript::query()->create([
        'plugin_code' => 'billboard',
        'name' => 'Sponsor banner: Code',
        'hash' => hash('sha256', $script),
        'script' => $script,
        'state' => $state,
        'requested_by' => $this->admin->id,
        'requested_at' => CarbonImmutable::parse('2026-06-02 10:00:00', 'UTC'),
        'decided_by' => $state === PluginScript::WAITING ? null : $this->admin->id,
        'decided_at' => $state === PluginScript::WAITING ? null : CarbonImmutable::parse('2026-06-03 10:00:00', 'UTC'),
    ]);
    $this->decisions = fn (): int => DB::table('security_events')->where('type', 'like', 'plugin_script.%')->count();
});

it('lists each plugin’s script with its state, the waiting ones first', function (): void {
    ($this->script)(PluginScript::APPROVED, 'window.approved = 1;');
    $waiting = ($this->script)(PluginScript::WAITING, 'window.waiting = 1;');

    $this->actingAs($this->admin)->get(route('settings.site-code'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/site-code')
            ->has('pluginScripts', 2)
            ->where('pluginScripts.0.id', (string) $waiting->id)
            ->where('pluginScripts.0.state', 'waiting')
            ->where('pluginScripts.0.plugin', 'billboard')
            ->where('pluginScripts.0.name', 'Sponsor banner: Code')
            ->where('pluginScripts.0.script', 'window.waiting = 1;')
            ->where('pluginScripts.0.requestedBy', 'Ada Admin')
            ->where('pluginScripts.0.requestedAt', '2026-06-02T10:00:00+00:00')
            ->where('pluginScripts.1.state', 'approved'));
});

it('approves, refuses and revokes a plugin’s script once the password is confirmed', function (): void {
    $approved = ($this->script)(PluginScript::WAITING, 'window.one = 1;');
    $refused = ($this->script)(PluginScript::WAITING, 'window.two = 2;');
    $as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed())->from(route('settings.site-code'));

    $as()->post(route('settings.site-code.scripts.approve', ['script' => $approved->id]))
        ->assertRedirect(route('settings.site-code'))
        ->assertInertiaFlash('toast.type', 'success');
    $as()->post(route('settings.site-code.scripts.refuse', ['script' => $refused->id]))
        ->assertRedirect(route('settings.site-code'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($approved->fresh()?->state)->toBe(PluginScript::APPROVED)
        ->and($refused->fresh()?->state)->toBe(PluginScript::REFUSED);

    $as()->post(route('settings.site-code.scripts.revoke', ['script' => $approved->id]))
        ->assertRedirect(route('settings.site-code'))
        ->assertInertiaFlash('toast.type', 'success');

    expect($approved->fresh())->toBeNull()
        ->and(($this->decisions)())->toBe(3);
});

it('says so, and writes nothing, when the script was already decided on', function (): void {
    $script = ($this->script)(PluginScript::APPROVED);

    $this->actingAs($this->admin)->withSession(scConfirmed())->from(route('settings.site-code'))
        ->post(route('settings.site-code.scripts.approve', ['script' => $script->id]))
        ->assertRedirect(route('settings.site-code'))
        ->assertInertiaFlash('toast.type', 'error');

    expect(($this->decisions)())->toBe(0);
});

it('refuses every decision to someone who may not change site code, and writes nothing', function (string $decision): void {
    $script = ($this->script)($decision === 'revoke' ? PluginScript::APPROVED : PluginScript::WAITING);

    $this->actingAs($this->editor)->withSession(scConfirmed())
        ->post(route("settings.site-code.scripts.{$decision}", ['script' => $script->id]))
        ->assertForbidden();

    expect($script->fresh()?->state)->toBe($decision === 'revoke' ? PluginScript::APPROVED : PluginScript::WAITING)
        ->and(($this->decisions)())->toBe(0);
})->with(['approve', 'refuse', 'revoke']);

it('asks for the password before approving, and approves nothing until then', function (): void {
    $script = ($this->script)();

    $this->actingAs($this->admin)->from(route('settings.site-code'))
        ->post(route('settings.site-code.scripts.approve', ['script' => $script->id]))
        ->assertRedirect(route('password.confirm'));

    expect($script->fresh()?->state)->toBe(PluginScript::WAITING)
        ->and(($this->decisions)())->toBe(0);
});

it('approves nothing while the host has switched site code off', function (): void {
    $script = ($this->script)();
    config(['teme.disallow_site_code' => true]);

    $this->actingAs($this->admin)->withSession(scConfirmed())->from(route('settings.site-code'))
        ->post(route('settings.site-code.scripts.approve', ['script' => $script->id]));

    expect($script->fresh()?->state)->toBe(PluginScript::WAITING)
        ->and(($this->decisions)())->toBe(0);
});
