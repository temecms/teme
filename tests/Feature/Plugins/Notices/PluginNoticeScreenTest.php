<?php

declare(strict_types=1);

use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Notices\NoticeMessage;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    PluginFixtures::use('relay');
    $manifest = PluginFixtures::manifest('relay');
    $this->record = PluginRecord::factory()->enabled()->create([
        'name' => 'tests/relay',
        'code' => 'relay',
        'version' => $manifest['version'],
        'consent' => ['capabilities' => $manifest['capabilities'], 'hosts' => [], 'hooks' => []],
    ]);

    /** The registries as the next request builds them. */
    $this->fresh = function (): void {
        app()->forgetScopedInstances();
        app()->forgetInstance(PreferenceRegistry::class);
    };
    ($this->fresh)();

    $this->ada = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->admin = User::factory()->create(['name' => 'Grace'])->assignRole('administrator');
    $this->failed = NoticePreferences::PREFIX.'plugin_relay.job_failed';
    $this->stalled = NoticePreferences::PREFIX.'plugin_relay.queue_stalled';
});

afterEach(fn () => PluginFixtures::forget());

it('lists a plugin’s kinds after core’s, in one group named once by the plugin, with the same switches', function (): void {
    $this->actingAs($this->admin)->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/notifications')
            ->where('notices', fn ($notices): bool => collect($notices)->pluck('key')->filter(fn (string $key): bool => str_contains($key, 'plugin_'))->isEmpty())
            ->where('pluginNotices', [[
                'plugin' => 'Relay',
                'notices' => [
                    ['key' => $this->failed, 'label' => 'Email me when an article I started fails', 'channels' => [['key' => 'mail', 'label' => 'Email', 'on' => false], ['key' => 'inbox', 'label' => 'Inbox', 'on' => true]], 'disabled' => false, 'switchedOff' => null],
                    ['key' => $this->stalled, 'label' => 'Email me when the article queue stops', 'channels' => [['key' => 'mail', 'label' => 'Email', 'on' => true]], 'disabled' => false, 'switchedOff' => null],
                ],
            ]]));
});

it('shows a kind for plugin managers only to those who hold it', function (): void {
    $this->actingAs($this->ada)->get(route('notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('pluginNotices.0.notices', fn ($notices): bool => collect($notices)->pluck('key')->all() === [$this->failed]));
});

it('saves a choice for a plugin’s kind', function (): void {
    $this->actingAs($this->ada)->from(route('notifications.edit'))
        ->patch(route('notifications.update'), ['preferences' => [$this->failed => ['mail', 'inbox']]])
        ->assertRedirect(route('notifications.edit'));

    expect(PreferencesFor::user($this->ada->refresh())[$this->failed] ?? null)->toBe(['mail', 'inbox']);
});

it('drops a disabled plugin’s group and keeps the choices stored', function (): void {
    lscrPrefer($this->ada, [$this->failed => ['mail']]);
    $this->record->forceFill(['state' => PluginRecord::DISABLED])->save();
    app(PluginRecords::class)->changed();
    ($this->fresh)();

    $this->actingAs($this->ada)->get(route('notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('pluginNotices', []));

    expect(((array) $this->ada->refresh()->preferences)[$this->failed] ?? null)->toBe(['mail']);
});

it('puts a plugin’s notice in the inbox as from that plugin, opening the link it gave', function (): void {
    app(PluginLoader::class)->load();
    $relay = app(LoadedPlugins::class)->running()['tests/relay'];
    app(CurrentJobActor::class)->set(JobActor::person($this->ada->id));

    $relay->notices->send('job_failed', [(string) $this->ada->id], new NoticeMessage('Your article couldn’t be written', ['The service didn’t answer.'], 'Open the settings', 'settings.show', ['group' => 'plugin.relay']));

    $this->actingAs($this->ada)->get(route('inbox.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.title', 'Your article couldn’t be written')
            ->where('rows.0.owner', 'relay')
            ->where('rows.0.plugin', 'Relay')
            ->where('rows.0.href', '/admin/settings/plugin.relay'));
});

it('marks core’s own inbox items as from no plugin', function (): void {
    app(SendToInbox::class)->execute(new SendToInboxInput(
        recipientId: (string) $this->ada->id,
        kind: 'job-finished',
        title: 'Import finished',
        actorId: (string) $this->ada->id,
    ));

    $this->actingAs($this->ada)->get(route('inbox.index'))
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.owner', 'core')->where('rows.0.plugin', null));
});
