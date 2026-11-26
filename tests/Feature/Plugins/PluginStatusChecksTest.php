<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\PluginStatusCheck;
use App\Domain\Plugins\Contributions\RecordedStatusFaults;
use App\Domain\Plugins\Contributions\StatusFaults;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Teme\Contracts\Status\StatusCheck as PluginCheck;
use Teme\Contracts\Status\StatusNotice;
use Tests\Fixtures\Plugins\Lists\NoticingPlugin;
use Tests\Fixtures\Plugins\Lists\ThrowingCheck;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/** Example sync's check, wrapped as Teme wraps a plugin's. */
function exampleCheck(?StatusNotice $notice): PluginStatusCheck
{
    return new PluginStatusCheck('acme/example-sync', 'example-sync', 'Example sync', (new NoticingPlugin($notice))->statusChecks()[0], app(StatusFaults::class));
}

it('shows a plugin’s notice as a banner to plugins.manage holders, linking to its settings when it asks', function (): void {
    $checks = new StatusChecks;
    $checks->add(exampleCheck(new StatusNotice('warning', 'Example sync needs its token.', true)), 'acme/example-sync');
    $editor = User::factory()->create()->assignRole('editor');

    expect($checks->forUser($this->admin)?->toArray())->toBe([
        'level' => 'warning',
        'message' => 'Example sync needs its token.',
        'actionLabel' => 'Open Example sync’s settings',
        'actionUrl' => route('settings.show', ['group' => 'plugin.example-sync']),
        'label' => 'Example sync',
        'plugin' => 'Example sync',
    ])
        ->and($checks->forUser($editor))->toBeNull()
        ->and(exampleCheck(new StatusNotice('error', 'The workspace refused the token.'))->banner()?->toArray())->toBe([
            'level' => 'error',
            'message' => 'The workspace refused the token.',
            'actionLabel' => null,
            'actionUrl' => null,
            'label' => '',
            'plugin' => null,
        ]);
});

it('ranks every plugin banner below Teme’s own', function (): void {
    $core = new readonly class implements StatusCheck
    {
        /**
         * The check's stable key, matched by Home's summary rows.
         *
         * @since next
         */
        public function key(): string
        {
            return 'test';
        }

        public function ability(): string
        {
            return 'plugins.manage';
        }

        public function label(): string
        {
            return 'Updates';
        }

        public function banner(): ?StatusBanner
        {
            // The lowest priority any core banner has (UpdateAvailable's info).
            return new StatusBanner('info', 'Teme 9.9.9 is available.', null, null, 5);
        }
    };
    app()->instance($core::class, $core);

    $checks = new StatusChecks;
    $checks->add(exampleCheck(new StatusNotice('error', 'The workspace refused the token.')), 'acme/example-sync');
    $checks->register($core::class);

    expect($checks->forUser($this->admin)?->message)->toBe('Teme 9.9.9 is available.')
        ->and(array_map(fn (string $level): ?int => exampleCheck(new StatusNotice($level, 'A notice.'))->banner()?->priority, ['error', 'warning', 'info']))->toBe([3, 2, 1]);
});

it('reports a check that throws, under its plugin, and shows nothing', function (): void {
    $faults = new class implements StatusFaults
    {
        /** @var list<array{string, string, string}> */
        public array $reported = [];

        public function report(string $owner, string $id, Throwable $failure): void
        {
            $this->reported[] = [$owner, $id, $failure->getMessage()];
        }
    };

    $check = new PluginStatusCheck('acme/x', 'x', 'X', new ThrowingCheck, $faults);

    expect($check->banner())->toBeNull()
        ->and($faults->reported)->toBe([['acme/x', 'acme/x#status', 'The workspace answered nothing.']])
        ->and(app(StatusFaults::class))->toBeInstanceOf(RecordedStatusFaults::class);
});

it('shows nothing when the plugin has nothing to say', function (): void {
    $checks = new StatusChecks;
    $checks->add(exampleCheck(null), 'acme/example-sync');

    expect($checks->forUser($this->admin))->toBeNull()
        ->and((new NoticingPlugin(null))->statusChecks()[0])->toBeInstanceOf(PluginCheck::class);
});

it('cuts a plugin’s banner text to 300 characters', function (): void {
    $long = str_repeat('é', 400);
    $fits = str_repeat('a', 300);

    expect(exampleCheck(new StatusNotice('warning', $long))->banner()?->message)->toBe(str_repeat('é', 299).'…')
        ->and(exampleCheck(new StatusNotice('warning', $fits))->banner()?->message)->toBe($fits);
});

it('logs a check that throws once a request, however often its banner is asked for', function (): void {
    // Since the join, through the plugins' fault spool, which logs it.
    hkRecordFaults();
    $check = new PluginStatusCheck('acme/x', 'x', 'X', new ThrowingCheck, app(StatusFaults::class));

    $check->banner();
    $check->banner();
    $check->banner();

    expect(hkFaults())->toBe([['owner' => 'acme/x', 'id' => 'acme/x#status', 'hook' => '', 'message' => 'The workspace answered nothing.']]);

    // The next request reports it again.
    app()->instance('request', Request::create('/admin'));
    $check->banner();

    expect(hkFaults())->toHaveCount(2);
});
