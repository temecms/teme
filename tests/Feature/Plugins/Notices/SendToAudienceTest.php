<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeMail;
use App\Domain\Notices\NoticeRegistry;
use App\Domain\Notices\NoticeSender;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Teme\Contracts\Notices\NoticeKind;
use Teme\Contracts\Notices\NoticeMessage;
use Teme\Contracts\Notices\Notices;
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

it('sends to Gate-only audience holders, not only Spatie permissions', function (): void {
    Notification::fake();
    Gate::define('tests.harbour', fn (User $user): bool => (string) $user->id === (string) $this->editor->id);
    app(NoticeRegistry::class)->register(new NoticeKind(
        'tests.harbour_alert',
        'Harbour alerts',
        audience: 'tests.harbour',
        channels: [NoticeKind::MAIL],
        defaults: [NoticeKind::MAIL],
    ));

    app(Notices::class)->sendToAudience('tests.harbour_alert', new NoticeMessage('The tide turned', []));

    Notification::assertSentTo($this->editor, NoticeMail::class);
    Notification::assertNotSentTo($this->admin, NoticeMail::class);
    Notification::assertNotSentTo($this->ada, NoticeMail::class);
});

it('sends a plugin-permission kind through sendToAudience to its holders', function (): void {
    Notification::fake();
    app()->instance(Elevation::class, new OperatorElevation);
    $contributes = PluginFixtures::manifest('relay')['contributes'];
    $contributes['permissions'] = [['id' => 'review', 'label' => 'Review articles', 'description' => 'Look over articles.', 'roles' => ['editor']]];
    $contributes['notices'][] = ['id' => 'review_waiting', 'label' => 'Email me when an article waits for review', 'audience' => 'review', 'channels' => ['mail'], 'defaults' => ['mail']];
    PluginFixtures::use(PluginFixtures::copy('relay', ['contributes' => $contributes]));
    $manifest = app(PluginRegistry::class)->find('tests/relay')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/relay',
        $manifest?->version() ?? '1.0.0',
        $manifest?->capabilities() ?? [],
        [],
        $manifest?->hooks() ?? [],
        null,
    ));
    app()->forgetScopedInstances();
    app()->forgetInstance(PreferenceRegistry::class);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();
    $relay = app(LoadedPlugins::class)->running()['tests/relay'] ?? null;
    expect($relay)->toBeInstanceOf(RelayPlugin::class, (string) app(LoadedPlugins::class)->failure('tests/relay'));

    $relay->notices->sendToAudience('review_waiting', new NoticeMessage('An article waits for review', []));

    Notification::assertSentTo($this->editor, NoticeMail::class);
    Notification::assertSentTo($this->admin, NoticeMail::class);
    Notification::assertNotSentTo($this->ada, NoticeMail::class);
});

it('drops sendToAudience deliveries beyond 100 an hour', function (): void {
    Notification::fake();
    $stalled = new NoticeMessage('The article queue stopped', []);

    foreach (range(1, 102) as $ignored) {
        $this->notices->sendToAudience('queue_stalled', $stalled);
    }

    Notification::assertSentToTimes($this->admin, NoticeMail::class, 100);
});

it('refuses sendToAudience for a kind with no audience', function (): void {
    Notification::fake();
    app(NoticeRegistry::class)->register(new NoticeKind(
        'tests.no_audience',
        'No audience',
        audience: null,
        channels: [NoticeKind::MAIL],
        defaults: [NoticeKind::MAIL],
        required: true,
    ));

    expect(fn () => app(NoticeSender::class)->sendToAudience('tests.no_audience', new NoticeMessage('Hello', [])))
        ->toThrow(InvalidArgumentException::class, 'Notice kind [tests.no_audience] has no audience to send to.');

    Notification::assertNothingSent();
});

it('sends nothing when the audience has no holders', function (): void {
    Notification::fake();
    Gate::define('tests.nobody', fn (): bool => false);
    app(NoticeRegistry::class)->register(new NoticeKind(
        'tests.empty_audience',
        'Empty audience',
        audience: 'tests.nobody',
        channels: [NoticeKind::MAIL],
        defaults: [NoticeKind::MAIL],
    ));

    app(Notices::class)->sendToAudience('tests.empty_audience', new NoticeMessage('Nobody home', []));

    Notification::assertNothingSent();
});

it('caps sendToAudience at 100 deliveries across many people in an hour', function (): void {
    Notification::fake();
    Cache::forget('plugin-notices-count:tests/relay');
    Cache::forget('plugin-notices-fault:tests/relay');
    $sends = function (): int {
        return User::query()->get()->sum(fn (User $user): int => count(Notification::sent($user, NoticeMail::class)));
    };

    User::factory()->count(60)->create()->each(fn (User $user) => $user->assignRole('administrator'));
    $this->notices->sendToAudience('queue_stalled', new NoticeMessage('The article queue stopped', []));
    $first = $sends();
    $holders = User::permission('plugins.manage')->count();

    User::factory()->count(60)->create()->each(fn (User $user) => $user->assignRole('administrator'));
    $this->notices->sendToAudience('queue_stalled', new NoticeMessage('Round two', []));

    expect($first)->toBe($holders)
        ->and($first)->toBeGreaterThan(50)->toBeLessThan(100)
        ->and($sends())->toBe(100);
});

it('refuses sendToAudience for a starter kind with no acting person', function (): void {
    Notification::fake();
    app(CurrentJobActor::class)->clear();

    expect(fn () => $this->notices->sendToAudience('job_failed', new NoticeMessage('Failed', [])))
        ->toThrow(InvalidArgumentException::class, 'This kind of notice goes only to the person the plugin’s work acts for.');

    Notification::assertNothingSent();
});

it('sends nothing from a rolled-back sendToAudience', function (): void {
    Notification::fake();

    try {
        DB::transaction(function (): void {
            $this->notices->sendToAudience('queue_stalled', new NoticeMessage('The article queue stopped', []));

            throw new RuntimeException('Undo.');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
});
