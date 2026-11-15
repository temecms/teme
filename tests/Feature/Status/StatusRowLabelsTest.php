<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Status\StatusNotice;
use Tests\Fixtures\Plugins\Lists\NoticingPlugin;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');

    // Only the checks each case registers: core's own may have something to say on a test site.
    app()->instance(StatusChecks::class, new StatusChecks);
});

/**
 * A core check with its own label, raising a banner at the level and priority given.
 */
function srlCheck(string $label, string $level, int $priority): StatusCheck
{
    return new readonly class($label, $level, $priority) implements StatusCheck
    {
        public function __construct(private string $label, private string $level, private int $priority) {}

        public function ability(): string
        {
            return 'settings.general.manage';
        }

        public function label(): string
        {
            return $this->label;
        }

        public function banner(): ?StatusBanner
        {
            return new StatusBanner($this->level, "{$this->label} wants a look.", null, null, $this->priority);
        }
    };
}

it('gives every core status check a label', function (): void {
    $checks = array_map(
        fn (string $file): string => 'App\\Domain\\Status\\Checks\\'.basename($file, '.php'),
        glob(app_path('Domain/Status/Checks/*.php')) ?: [],
    );

    expect($checks)->not->toBeEmpty();

    foreach ($checks as $check) {
        expect(app($check)->label())->toBeString()->not->toBe('', "{$check} has no label");
    }
});

it('shares a core banner with its label and no plugin', function (): void {
    // Registered by its class, as core's checks are.
    $check = srlCheck('Testing', 'warning', 20);
    app()->instance($check::class, $check);
    app(StatusChecks::class)->register($check::class);

    $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('siteStatus.0.label', 'Testing')
        ->where('siteStatus.0.plugin', null)
        ->where('statusBanner.label', 'Testing')
        ->where('statusBanner.plugin', null));
});

it('labels a plugin’s banner with the plugin’s name, for those who manage plugins', function (): void {
    app(Contributions::class)->add('acme/noticing', 'noticing', 'Noticing', '1.0.0', [], new NoticingPlugin(new StatusNotice('warning', 'Noticing needs its token.')));
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->has('siteStatus', 1)
        ->where('siteStatus.0.message', 'Noticing needs its token.')
        ->where('siteStatus.0.label', 'Noticing')
        ->where('siteStatus.0.plugin', 'Noticing'));

    $this->actingAs($editor)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('siteStatus', [])
        ->where('statusBanner', null));
});

it('keeps the most pressing first', function (): void {
    $checks = app(StatusChecks::class);
    $checks->add(srlCheck('Testing', 'warning', 20), 'core');
    $checks->add(srlCheck('Pressing', 'error', 40), 'core');

    expect(array_map(
        fn (StatusBanner $banner): array => [$banner->level, $banner->label, $banner->plugin],
        $checks->bannersFor($this->admin),
    ))->toBe([
        ['error', 'Pressing', null],
        ['warning', 'Testing', null],
    ]);
});
