<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Status\StatusNotice;
use Tests\Fixtures\Plugins\Lists\NoticingPlugin;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Only the plugins' checks: core's own may have something to say on a test site.
    app()->instance(StatusChecks::class, new StatusChecks);
});

/**
 * What Example sync's teme-plugin.json adds to the admin.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function exampleContributes(): array
{
    return [
        'settings' => [
            ['key' => 'token', 'type' => 'secret', 'label' => 'Token', 'description' => 'Lets Teme tell your workspace.'],
            ['key' => 'project', 'type' => 'text', 'label' => 'Project', 'description' => 'The workspace project to tell.'],
        ],
        'admin' => [
            ['surface' => 'tools', 'id' => 'open', 'label' => 'Open Sync', 'description' => 'Your keyword workspace.', 'icon' => 'external-link', 'to' => 'https://sync.example/projects'],
            ['surface' => 'tools', 'id' => 'settings', 'label' => 'Sync settings', 'to' => 'settings'],
        ],
    ];
}

it('registers a plugin’s settings, links and checks together, under its name', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $plugin = new NoticingPlugin(new StatusNotice('warning', 'Example sync needs its token.', true));

    $problems = app(Contributions::class)->add('acme/example-sync', 'example-sync', 'Example sync', '1.2.0', exampleContributes(), $plugin);

    $settings = app(SettingsRegistry::class);
    $links = app(ToolLinks::class)->all();

    expect($problems)->toBe([])
        ->and($settings->find('plugin.example-sync')?->settings)->toHaveCount(2)
        ->and($settings->find('plugin.example-sync')?->sensitive)->toBeTrue()
        ->and($settings->ownerOf('plugin.example-sync'))->toBe('acme/example-sync')
        ->and(array_map(fn (ToolLink $link): array => [$link->owner, $link->code, $link->id, $link->icon, $link->to], $links))->toBe([
            ['acme/example-sync', 'example-sync', 'open', 'external-link', 'https://sync.example/projects'],
            ['acme/example-sync', 'example-sync', 'settings', 'plug', 'settings'],
        ])
        ->and($links[1]->description)->toBe('Added by the Example sync plugin.')
        ->and(app(StatusChecks::class)->forUser($admin)?->actionLabel)->toBe('Open Example sync’s settings');
});

it('registers nothing when any part is wrong, and says why', function (): void {
    $contributes = exampleContributes();
    $contributes['admin'][0]['to'] = 'http://sync.example';

    $problems = app(Contributions::class)->add('acme/example-sync', 'example-sync', 'Example sync', '1.2.0', $contributes, new NoticingPlugin(null));

    expect($problems)->toBe(['A link must go to settings or to an https:// address.'])
        ->and(app(SettingsRegistry::class)->find('plugin.example-sync'))->toBeNull()
        ->and(app(ToolLinks::class)->all())->toBe([]);
});

it('takes a plugin’s checks only when it implements ChecksStatus', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    $notice = new StatusNotice('error', 'Look at me.');
    // Has the method, but never said it raises notices.
    $lookalike = new readonly class($notice)
    {
        public function __construct(private StatusNotice $notice) {}

        /** @return list<object> */
        public function statusChecks(): array
        {
            return (new NoticingPlugin($this->notice))->statusChecks();
        }
    };

    expect(app(Contributions::class)->add('acme/lookalike', 'lookalike', 'Lookalike', '1.0.0', [], $lookalike))->toBe([])
        ->and(app(StatusChecks::class)->forUser($admin))->toBeNull();

    app(Contributions::class)->add('acme/noticing', 'noticing', 'Noticing', '1.0.0', [], new NoticingPlugin($notice));

    expect(app(StatusChecks::class)->forUser($admin)?->message)->toBe('Look at me.');
});
