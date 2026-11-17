<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeRegistry;
use App\Domain\Notices\Queries\NoticeChoices;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginWords;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PluginFixtures::loadFromOriginal('relay');
    $this->seed(RoleSeeder::class);

    $this->enable = function (string $path, array $changes = []): PluginRecord {
        $manifest = json_decode((string) file_get_contents($path.'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);

        return PluginRecord::factory()->enabled()->create([
            'name' => $manifest['name'],
            'code' => $manifest['code'],
            'version' => $manifest['version'],
            'consent' => PluginFixtures::agreed($manifest),
            ...$changes,
        ]);
    };

    /** The registry as a new request builds it. */
    $this->registry = function (): NoticeRegistry {
        app()->forgetScopedInstances();

        return app(NoticeRegistry::class);
    };

    $this->listed = fn (User $user): array => array_column(app(NoticeChoices::class)->forScreen($user), 'label', 'key');
});

afterEach(fn () => PluginFixtures::forget());

it('registers an enabled plugin’s kinds under its stem, beside core’s', function (): void {
    PluginFixtures::use('relay');
    ($this->enable)(PluginFixtures::path('relay'));

    $registry = ($this->registry)();
    $failed = $registry->get('plugin_relay.job_failed');
    $stalled = $registry->get('plugin_relay.queue_stalled');

    expect($failed?->label)->toBe('Email me when an article I started fails')
        ->and($failed?->audience)->toBeNull()
        ->and($failed?->channels)->toBe(['mail', 'inbox'])
        ->and($failed?->defaults)->toBe(['inbox'])
        ->and($failed?->required)->toBeFalse()
        ->and($stalled?->audience)->toBe('plugins.manage')
        ->and($registry->get('comments.waiting'))->not->toBeNull();
});

it('lists a plugin’s kinds to each person with the audience', function (): void {
    PluginFixtures::use('relay');
    ($this->enable)(PluginFixtures::path('relay'));
    ($this->registry)();
    $admin = User::factory()->create()->assignRole('administrator');
    $author = User::factory()->create()->assignRole('author');

    expect(($this->listed)($admin))->toMatchArray([
        'notifications.plugin_relay.job_failed' => 'Email me when an article I started fails',
        'notifications.plugin_relay.queue_stalled' => 'Email me when the article queue stops',
    ])
        ->and(($this->listed)($author))->toHaveKey('notifications.plugin_relay.job_failed')
        ->not->toHaveKey('notifications.plugin_relay.queue_stalled');
});

it('neither registers nor lists the kinds of a plugin that isn’t enabled, or wasn’t granted notices.send', function (): void {
    PluginFixtures::use('relay');
    $record = ($this->enable)(PluginFixtures::path('relay'));
    ($this->registry)();
    $admin = User::factory()->create()->assignRole('administrator');

    $record->update(['state' => PluginRecord::DISABLED]);
    app(PluginRecords::class)->changed();

    expect(($this->listed)($admin))->not->toHaveKey('notifications.plugin_relay.job_failed')
        ->and(($this->registry)()->get('plugin_relay.job_failed'))->toBeNull();

    $record->update(['state' => PluginRecord::ENABLED, 'consent' => ['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => []]]);
    app(PluginRecords::class)->changed();

    expect(($this->registry)()->get('plugin_relay.job_failed'))->toBeNull();
});

it('refuses a manifest whose kinds of notice break a rule', function (array $changes, string $why): void {
    $copy = PluginFixtures::copy('relay', $changes);
    PluginFixtures::use($copy);

    expect(app(PluginRegistry::class)->find('tests/relay')?->problems)->toContain($why);
})->with([
    'more than five' => [
        ['contributes' => ['notices' => array_map(fn (int $i): array => ['id' => 'kind_'.$i, 'label' => 'Kind '.$i, 'audience' => 'starter', 'channels' => ['inbox']], range(1, 6))]],
        'A plugin can declare at most 5 kinds of notice.',
    ],
    'a bad id' => [
        ['contributes' => ['notices' => [['id' => 'Job-Failed', 'label' => 'Failed', 'audience' => 'starter', 'channels' => ['inbox']]]]],
        'A kind of notice’s id must be 2 to 32 lower-case letters, digits and underscores, starting with a letter.',
    ],
    'an unknown audience' => [
        ['contributes' => ['notices' => [['id' => 'job_failed', 'label' => 'Failed', 'audience' => 'everyone', 'channels' => ['inbox']]]]],
        'The kind of notice job_failed must go to starter or plugins.manage.',
    ],
    'a default not among its channels' => [
        ['contributes' => ['notices' => [['id' => 'job_failed', 'label' => 'Failed', 'audience' => 'starter', 'channels' => ['inbox'], 'defaults' => ['mail']]]]],
        'The kind of notice job_failed has a default that isn’t one of its channels.',
    ],
    'required' => [
        ['contributes' => ['notices' => [['id' => 'job_failed', 'label' => 'Failed', 'audience' => 'starter', 'channels' => ['inbox'], 'required' => true]]]],
        'A kind of notice has an id, a label, an audience, channels and defaults, and nothing else; a plugin’s notices are never required.',
    ],
    'no notices.send' => [
        ['capabilities' => ['content.read'], 'plugin' => 'Tests\\Fixtures\\Plugins\\RelayPro\\RelayProReader'],
        'It declares kinds of notice, but the plugin doesn’t ask for notices.send.',
    ],
]);

it('names the kinds of notice in the consent', function (): void {
    PluginFixtures::use('relay');

    $exit = Artisan::call('teme:plugins:enable', ['name' => 'tests/relay']);

    expect(PluginWords::capability('notices.send', notices: ['Email me when an article I started fails']))
        ->toBe('Send notices to people on this site, by email or in their inbox: Email me when an article I started fails')
        ->and($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Send notices to people on this site, by email or in their inbox: Email me when an article I started fails; Email me when the article queue stops');
});
