<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeMail;
use App\Domain\Notices\NoticeRegistry;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Teme\Contracts\Notices\NoticeMessage;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Relay\RelayPlugin;

uses(RefreshDatabase::class);

/*
 * A plugin's kind of notice for the holders of a permission it declares,
 * and a notice that links to the plugin's own area
 * (docs/plans/plugin-sockets-3.md, D-T5).
 */
beforeEach(function (): void {
    PluginFixtures::loadFromOriginal('relay');
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    app()->instance(Elevation::class, new OperatorElevation);

    $contributes = PluginFixtures::manifest('relay')['contributes'];
    $contributes['permissions'] = [['id' => 'review', 'label' => 'Review articles', 'description' => 'Look over articles before they go out.', 'roles' => ['editor']]];
    $contributes['notices'][] = ['id' => 'review_waiting', 'label' => 'Email me when an article waits for review', 'audience' => 'review', 'channels' => ['mail'], 'defaults' => ['mail']];
    $this->contributes = $contributes;

    PluginFixtures::use(PluginFixtures::copy('relay', ['contributes' => $contributes]));
    $manifest = app(PluginRegistry::class)->find('tests/relay')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/relay', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], [], $manifest?->hooks() ?? [], null));
    app()->forgetScopedInstances();
    app()->forgetInstance(PreferenceRegistry::class);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();

    $relay = app(LoadedPlugins::class)->running()['tests/relay'] ?? null;
    expect($relay)->toBeInstanceOf(RelayPlugin::class, (string) app(LoadedPlugins::class)->failure('tests/relay'));
    $this->notices = $relay->notices;

    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

afterEach(fn () => PluginFixtures::forget());

it('sends a kind for a permission the plugin declares only to its holders', function (): void {
    Notification::fake();

    expect(app(NoticeRegistry::class)->get('plugin_relay.review_waiting')?->audience)->toBe('plugin.relay.review');

    $this->notices->send('review_waiting', [(string) $this->author->id, (string) $this->editor->id, (string) $this->admin->id], new NoticeMessage('An article waits for review', ['“Winter sale” is ready.']));

    Notification::assertSentTo($this->editor, NoticeMail::class);
    Notification::assertSentTo($this->admin, NoticeMail::class);
    Notification::assertNotSentTo($this->author, NoticeMail::class);
});

it('refuses an audience naming a permission the plugin doesn’t declare', function (): void {
    $contributes = $this->contributes;
    $contributes['notices'][2]['audience'] = 'publish';
    PluginFixtures::use(PluginFixtures::copy('relay', ['contributes' => $contributes]));

    expect(app(PluginRegistry::class)->find('tests/relay')?->problems)->toContain('The kind of notice review_waiting must go to starter, plugins.manage or a permission the plugin declares.');
});

it('lets a notice link to the plugin’s own area, and to no other plugin’s', function (): void {
    Notification::fake();
    $link = fn (string $code): NoticeMessage => new NoticeMessage('An article waits for review', [], 'Open the queue', 'plugins.area.show', ['code' => $code]);

    $this->notices->send('review_waiting', [(string) $this->editor->id], $link('relay'));

    Notification::assertSentTo($this->editor, NoticeMail::class);
    expect(fn () => $this->notices->send('review_waiting', [(string) $this->editor->id], $link('placard')))
        ->toThrow(InvalidArgumentException::class, 'A plugin’s notice may link only to its own settings, its own area or an item’s editor.');
});
