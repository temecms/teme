<?php

declare(strict_types=1);

use App\Domain\Notices\Channels\InboxChannel;
use App\Domain\Notices\NoticeMail;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Notices\NoticeSender;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Notices\NoticeRate;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
    // The registries as the next request builds them, once the plugin is enabled.
    app()->forgetScopedInstances();
    app()->forgetInstance(PreferenceRegistry::class);
    app(PluginLoader::class)->load();

    $relay = app(LoadedPlugins::class)->running()['tests/relay'] ?? null;
    expect($relay)->toBeInstanceOf(RelayPlugin::class, (string) app(LoadedPlugins::class)->failure('tests/relay'));
    $this->notices = $relay->notices;

    $this->ada = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->admin = User::factory()->create(['name' => 'Grace'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Linus'])->assignRole('editor');
    $this->message = new NoticeMessage('Your article couldn’t be written', ['The service didn’t answer.'], 'Open the settings', 'settings.show', ['group' => 'plugin.relay']);

    /** Runs as a plugin job started by this person, as RunPluginJob does. */
    $this->asJobOf = fn (User $person) => app(CurrentJobActor::class)->set(JobActor::person($person->id));
});

afterEach(fn () => PluginFixtures::forget());

it('offers the plugin core’s Notices contract through its own door', function (): void {
    expect($this->notices)->toBeInstanceOf(Notices::class)
        ->and($this->notices)->not->toBeInstanceOf(NoticeSender::class);
});

it('sends a starter’s kind to the person the job runs for, by the channels they chose, after commit and never after a rollback', function (): void {
    Notification::fake();
    lscrPrefer($this->ada, [NoticePreferences::PREFIX.'plugin_relay.job_failed' => ['mail', 'inbox']]);
    ($this->asJobOf)($this->ada);

    DB::transaction(function (): void {
        $this->notices->send('job_failed', [(string) $this->ada->id], $this->message);

        Notification::assertNothingSent();
    });

    Notification::assertSentTo($this->ada, NoticeMail::class, fn (NoticeMail $mail, array $channels): bool => $mail->kind === 'plugin_relay.job_failed' && $channels === ['mail', InboxChannel::class]);

    try {
        DB::transaction(function (): void {
            $this->notices->send('plugin_relay.job_failed', [(string) $this->ada->id], $this->message);

            throw new RuntimeException('Undo.');
        });
    } catch (RuntimeException) {
    }

    Notification::assertSentToTimes($this->ada, NoticeMail::class, 1);
});

it('sends a kind for plugins.manage only to those who hold it', function (): void {
    Notification::fake();

    $this->notices->send('queue_stalled', [(string) $this->admin->id, (string) $this->editor->id], new NoticeMessage('The article queue stopped', ['Nothing ran for an hour.']));

    Notification::assertSentTo($this->admin, NoticeMail::class);
    Notification::assertNotSentTo($this->editor, NoticeMail::class);
});

it('refuses what a plugin’s notice may not do', function (Closure $send, string $why): void {
    Notification::fake();
    ($this->asJobOf)($this->ada);

    expect(fn () => $send->call($this))->toThrow(InvalidArgumentException::class, $why);
    Notification::assertNothingSent();
})->with([
    'a kind it doesn’t declare' => [fn () => $this->notices->send('comments.waiting', [(string) $this->admin->id], $this->message), 'The plugin doesn’t declare the kind of notice comments.waiting.'],
    'a starter’s kind to someone else' => [fn () => $this->notices->send('job_failed', [(string) $this->admin->id], $this->message), 'This kind of notice goes only to the person the plugin’s work acts for.'],
    'a starter’s kind to more than the starter' => [fn () => $this->notices->send('job_failed', [(string) $this->ada->id, (string) $this->admin->id], $this->message), 'This kind of notice goes only to the person the plugin’s work acts for.'],
    'a quote' => [fn () => $this->notices->send('job_failed', [(string) $this->ada->id], new NoticeMessage('Failed', [], quote: 'Someone’s words')), 'A plugin’s notice can’t quote anyone.'],
    'too many lines' => [fn () => $this->notices->send('job_failed', [(string) $this->ada->id], new NoticeMessage('Failed', array_fill(0, 6, 'A line.'))), 'A plugin’s notice has at most 5 lines of up to 500 characters each.'],
    'a subject too long' => [fn () => $this->notices->send('job_failed', [(string) $this->ada->id], new NoticeMessage(str_repeat('a', 121), [])), 'A plugin’s notice needs a subject of 1 to 120 characters.'],
    'a route it may not link to' => [fn () => $this->notices->send('job_failed', [(string) $this->ada->id], new NoticeMessage('Failed', [], 'Open', 'inbox.index')), 'A plugin’s notice may link only to its own settings, its own area or an item’s editor.'],
    'another plugin’s settings' => [fn () => $this->notices->send('job_failed', [(string) $this->ada->id], new NoticeMessage('Failed', [], 'Open', 'settings.show', ['group' => 'plugin.other'])), 'A plugin’s notice may link only to its own settings, its own area or an item’s editor.'],
    'an address' => [fn () => $this->notices->sendToAddress('job_failed', 'ada@example.com', $this->message), 'A plugin sends notices only to people with an account.'],
]);

it('lets a starter’s kind link to an item’s editor', function (): void {
    Notification::fake();
    ($this->asJobOf)($this->ada);

    $this->notices->send('job_failed', [(string) $this->ada->id], new NoticeMessage('Failed', [], 'Open the draft', 'content.edit', ['content' => '01jc4r2v9d8f7g6h5j4k3m2n1p']));

    Notification::assertSentTo($this->ada, NoticeMail::class);
});

it('drops the deliveries beyond 100 an hour, and records one fault', function (): void {
    Notification::fake();
    hkRecordFaults();
    $stalled = new NoticeMessage('The article queue stopped', []);

    foreach (range(1, 102) as $ignored) {
        $this->notices->send('queue_stalled', [(string) $this->admin->id], $stalled);
    }

    Notification::assertSentToTimes($this->admin, NoticeMail::class, 100);
    expect(hkFaults())->toBe([[
        'owner' => 'tests/relay',
        'id' => 'tests/relay#notices',
        'hook' => Notices::class,
        'message' => 'It sent more than 100 notices in an hour; the rest were dropped.',
    ]]);
});

it('counts a plugin’s deliveries in one step, so successive takes never pass 100 together', function (): void {
    $rate = app(NoticeRate::class);

    expect([$rate->take('tests/atomic', 60), $rate->take('tests/atomic', 60), $rate->take('tests/atomic', 1)])->toBe([60, 40, 0])
        ->and(Cache::get('plugin-notices-count:tests/atomic'))->toBe(121);
});
