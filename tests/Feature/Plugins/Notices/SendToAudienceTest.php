<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeMail;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Teme\Contracts\Notices\NoticeMessage;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Relay\RelayPlugin;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    PluginFixtures::use('relay');
    $manifest = PluginFixtures::manifest('relay');
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/relay',
        'code' => 'relay',
        'version' => $manifest['version'],
        'consent' => PluginFixtures::agreed($manifest),
    ]);
    app()->forgetScopedInstances();
    app()->forgetInstance(PreferenceRegistry::class);
    app(PluginLoader::class)->load();

    $relay = app(LoadedPlugins::class)->running()['tests/relay'] ?? null;
    expect($relay)->toBeInstanceOf(RelayPlugin::class, (string) app(LoadedPlugins::class)->failure('tests/relay'));
    $this->notices = $relay->notices;

    $this->ada = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->admin = User::factory()->create(['name' => 'Grace'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Linus'])->assignRole('editor');
});

afterEach(fn () => PluginFixtures::forget());

it('sends a plugins.manage kind to every holder without the plugin listing accounts', function (): void {
    Notification::fake();

    $this->notices->sendToAudience('queue_stalled', new NoticeMessage('The article queue stopped', ['Nothing ran for an hour.']));

    Notification::assertSentTo($this->admin, NoticeMail::class);
    Notification::assertNotSentTo($this->editor, NoticeMail::class);
    Notification::assertNotSentTo($this->ada, NoticeMail::class);
});

it('sends a starter kind through sendToAudience only to the person the work acts for', function (): void {
    Notification::fake();
    app(CurrentJobActor::class)->set(JobActor::person($this->ada->id));

    $this->notices->sendToAudience('job_failed', new NoticeMessage('Your article couldn’t be written', ['The service didn’t answer.']));

    Notification::assertSentTo($this->ada, NoticeMail::class);
    Notification::assertNotSentTo($this->admin, NoticeMail::class);
});

it('refuses sendToAudience for a kind the plugin doesn’t declare', function (): void {
    Notification::fake();

    expect(fn () => $this->notices->sendToAudience('comments.waiting', new NoticeMessage('Hello', [])))
        ->toThrow(InvalidArgumentException::class, 'The plugin doesn’t declare the kind of notice comments.waiting.');

    Notification::assertNothingSent();
});
