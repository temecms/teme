<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeMail;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Plugins\Operations\SavePluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Site\Insertions\Scripts\PluginScript;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Content\WriteRefused;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/*
 * Inline scripts in a code field that may hold them (docs/plans/plugin-sockets-2.md,
 * A-T9, and the decided Q2): a save asks for each one's approval inside its
 * transaction; the form says which wait; a person who may change site code
 * approves one there, with the password; a plugin never approves its own.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    $this->unit = '<ins class="adsbygoogle"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
    $this->ad = fn (string $code): array => [
        'name' => 'Sponsor banner',
        'location' => 'between_items',
        'versions' => [['label' => 'Wide', 'code' => $code, 'weight' => '1']],
        'types' => [],
        'terms' => [],
    ];
    $this->save = fn (string $code, ?string $id = null): array => app(SavePluginRecord::class)->execute(new SavePluginRecordInput('placard', 'ad', $id, ($this->ad)($code), (string) $this->editor->id));
    $this->waiting = fn (): array => PluginScript::query()->where('plugin_code', 'placard')->where('state', PluginScript::WAITING)->pluck('script')->all();
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('asks once for each inline script a saved code field holds, and not again when it is saved again', function (): void {
    $saved = ($this->save)($this->unit);

    expect(($this->waiting)())->toBe(['(adsbygoogle = window.adsbygoogle || []).push({});'])
        ->and(PluginScript::query()->sole()->name)->toBe('Ad: Ad code');

    ($this->save)($this->unit, $saved['id']);

    expect(PluginScript::query()->count())->toBe(1);
});

it('asks for nothing when the save is refused', function (): void {
    PlacardArea::$on['save'] = fn (): Saved => throw new WriteRefused('An ad with this name already runs.');

    expect(fn () => ($this->save)($this->unit))->toThrow(ValidationException::class)
        ->and(PluginScript::query()->count())->toBe(0);
});

it('asks for a code setting’s scripts when a plugin’s settings are saved', function (): void {
    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.placard', ['fallback' => '<script>window.fallback = 1;</script>'], [], [], $version, (string) $this->admin->id));

    expect(($this->waiting)())->toBe(['window.fallback = 1;']);
});

it('names a code setting’s script in words that translate', function (): void {
    app('translator')->addLines(['*.Settings: :label' => 'Ajustes: :label'], 'es');
    app()->setLocale('es');
    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.placard', ['fallback' => '<script>window.fallback = 1;</script>'], [], [], $version, (string) $this->admin->id));

    expect(PluginScript::query()->sole()->name)->toBe('Ajustes: Fallback code');
});

it('says under the field which scripts wait, and offers approval only to a person who may change site code', function (): void {
    $saved = ($this->save)($this->unit);
    $script = PluginScript::query()->sole();
    $form = fn (User $person) => $this->actingAs($person)->get(route('plugins.area.edit', ['code' => 'placard', 'record' => 'ad', 'id' => $saved['id']]));

    $form($this->editor)->assertInertia(fn (Assert $page) => $page
        ->where('scripts.waiting', ['values.versions.0.code' => [['id' => (string) $script->id, 'script' => '(adsbygoogle = window.adsbygoogle || []).push({});']]])
        ->where('scripts.approver', false));
    $form($this->admin)->assertInertia(fn (Assert $page) => $page->where('scripts.approver', true));
});

it('tells the people who may approve when a save asks for a new script, once, linking to where it was written', function (): void {
    Notification::fake();
    $saved = ($this->save)($this->unit);
    ($this->save)($this->unit, $saved['id']);

    Notification::assertSentTo($this->admin, NoticeMail::class, fn (NoticeMail $mail): bool => $mail->kind === 'site_code.script_waiting'
        && $mail->message->actionRoute === 'plugins.area.show'
        && $mail->message->actionParameters === ['code' => 'placard']);
    Notification::assertSentTimes(NoticeMail::class, 1);
    Notification::assertNotSentTo($this->editor, NoticeMail::class);
});

it('tells the people who may approve about a code setting’s new script, linking to the plugin’s settings', function (): void {
    Notification::fake();
    $other = User::factory()->create()->assignRole('administrator');
    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.placard', ['fallback' => '<script>window.fallback = 1;</script>'], [], [], $version, (string) $this->admin->id));

    Notification::assertSentTo($other, NoticeMail::class, fn (NoticeMail $mail): bool => $mail->message->actionRoute === 'settings.show'
        && $mail->message->actionParameters === ['group' => 'plugin.placard']);
    Notification::assertNotSentTo($this->admin, NoticeMail::class);
});

it('says under a code setting which scripts wait on the plugin’s settings page', function (): void {
    $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.placard', ['fallback' => '<script>window.fallback = 1;</script>'], [], [], $version, (string) $this->admin->id));
    $script = PluginScript::query()->sole();

    $this->actingAs($this->admin)->get(route('settings.show', ['group' => 'plugin.placard']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pluginScripts.code', 'placard')
            ->where('pluginScripts.waiting', ['values.fallback' => [['id' => (string) $script->id, 'script' => 'window.fallback = 1;']]])
            ->where('pluginScripts.approver', true));
});

it('approves a waiting script from the form for a person who may change site code, with the password', function (): void {
    ($this->save)($this->unit);
    $script = PluginScript::query()->sole();
    $approve = fn (User $person) => $this->actingAs($person)
        ->withSession(scConfirmed())
        ->from('/admin/plugin/placard')
        ->post(route('plugins.area.script', ['code' => 'placard', 'script' => $script->id]));

    $approve($this->admin)->assertRedirect('/admin/plugin/placard')
        ->assertInertiaFlash('toast.type', 'success');

    expect($script->fresh()?->state)->toBe(PluginScript::APPROVED);
});

it('refuses, approving nothing, a person who may not change site code, or one whose password window has closed', function (Closure $refused): void {
    ($this->save)($this->unit);
    $script = PluginScript::query()->sole();

    $refused->call($this, (string) $script->id);

    expect($script->fresh()?->state)->toBe(PluginScript::WAITING);
})->with([
    'an editor' => [function (string $id): void {
        $this->actingAs($this->editor)->post(route('plugins.area.script', ['code' => 'placard', 'script' => $id]))->assertInertiaFlash('toast.type', 'error');
    }],
    'no password' => [function (string $id): void {
        $this->actingAs($this->admin)->withHeader('X-Teme-Ask-In-Place', '1')->post(route('plugins.area.script', ['code' => 'placard', 'script' => $id]))->assertStatus(423);
    }],
    'another plugin’s script' => [function (string $id): void {
        $this->actingAs($this->admin)->post(route('plugins.area.script', ['code' => 'billboard', 'script' => $id]))->assertNotFound();
    }],
]);

it('never approves another plugin’s script through this plugin’s form', function (): void {
    ($this->save)($this->unit);
    $other = PluginScript::query()->sole()->replicate();
    $other->plugin_code = 'billboard';
    $other->save();

    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->post(route('plugins.area.script', ['code' => 'placard', 'script' => $other->id]))
        ->assertNotFound();

    expect($other->fresh()?->state)->toBe(PluginScript::WAITING);
});
