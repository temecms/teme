<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Status\Checks\ThemeUsable;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsRegistrar;

uses(RefreshDatabase::class);

/** A settings group a plugin might declare, for these tests. */
function ownedGroup(string $key = 'plugin.example-sync'): SettingsGroup
{
    return new SettingsGroup(
        key: $key,
        label: 'Example sync',
        description: 'Tells your keyword workspace what you publish.',
        section: 'plugins',
        ability: 'plugins.manage',
        settings: [Setting::text('project', 'Project', 'The workspace project to tell.', '1.0.0')],
        since: '1.0.0',
    );
}

/** A status check answering with a banner of this priority, or none. */
function ownedCheck(?int $priority): StatusCheck
{
    return new readonly class($priority) implements StatusCheck
    {
        public function __construct(private ?int $priority) {}

        public function ability(): string
        {
            return 'plugins.manage';
        }

        public function banner(): ?StatusBanner
        {
            return $this->priority === null ? null : new StatusBanner('warning', "Priority {$this->priority}.", null, null, $this->priority);
        }
    };
}

it('records core as the owner of every group, check and page core registers', function (): void {
    $settings = app(SettingsRegistry::class);

    expect($settings->ownerOf('general'))->toBe('core')
        ->and(app(StatusChecks::class)->ownerOf(ThemeUsable::class))->toBe('core')
        ->and(app(ToolLinks::class)->all())->toBe([]);

    foreach ($settings->all() as $group) {
        expect($settings->ownerOf($group->key))->toBe('core');
    }
});

it('records the name given as the owner of a group, a check instance and a Tools link', function (): void {
    $settings = new SettingsRegistry;
    $settings->group(ownedGroup(), 'acme/example-sync');

    $check = ownedCheck(null);
    $checks = new StatusChecks;
    $checks->add($check, 'acme/example-sync');

    $links = new ToolLinks;
    $links->add(new ToolLink('acme/example-sync', 'example-sync', 'open', 'Open Sync', 'Your keyword workspace.', 'external-link', 'https://sync.example'));

    expect($settings->ownerOf('plugin.example-sync'))->toBe('acme/example-sync')
        ->and($settings->ownerOf('nothing'))->toBeNull()
        ->and($checks->ownerOf($check))->toBe('acme/example-sync')
        ->and($checks->ownerOf(ownedCheck(null)))->toBeNull()
        ->and(array_map(fn (ToolLink $link): string => $link->owner, $links->all()))->toBe(['acme/example-sync']);
});

it('asks a check instance for its banner as it asks a registered class', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    $registered = ownedCheck(7);
    app()->instance($registered::class, $registered);

    $checks = new StatusChecks;
    $checks->register($registered::class);
    $checks->add(ownedCheck(3), 'acme/example-sync');

    expect($checks->forUser($admin)?->message)->toBe('Priority 7.');

    $quiet = new StatusChecks;
    $quiet->register($registered::class);
    $quiet->add(ownedCheck(9), 'acme/example-sync');

    expect($quiet->forUser($admin)?->message)->toBe('Priority 9.');
});

it('keeps the published SettingsRegistrar’s one-parameter group() working', function (): void {
    $settings = new SettingsRegistry;
    $declare = fn (SettingsRegistrar $registrar) => $registrar->group(ownedGroup('example'));

    $declare($settings);

    expect($settings->find('example')?->label)->toBe('Example sync')
        ->and($settings->ownerOf('example'))->toBe('core');
});
