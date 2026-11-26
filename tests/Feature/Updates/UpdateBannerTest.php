<?php

declare(strict_types=1);

use App\Domain\Status\Checks\UpdateAvailable;
use App\Domain\Status\StatusChecks;
use App\Domain\Support\Security\Elevation;
use App\Domain\Updates\CheckForUpdates;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // Backups that are fine, so their own banner never outranks the update's (PreviewAndActivateTest.php:181).
    HealthyBackups::seed();
    $this->admin = User::factory()->create()->assignRole('administrator');
    // Only this check, so another admin banner (a menu not chosen) never outranks it here.
    $this->checks = new StatusChecks;
    $this->checks->register(UpdateAvailable::class);
    config(['teme.version' => '0.1.0-rc.13', 'teme.tagged' => true, 'teme.updates.channel' => null, 'teme.updates.check' => true]);
    $this->list = function (array $items): void {
        updOperator();
        updFake(Http::response($items, 200));
        app(CheckForUpdates::class)->run();
        app()->forgetInstance(Elevation::class);
    };
});

it('tells administrators when a newer release exists, with the way to it', function (): void {
    ($this->list)([updItem('0.1.0-rc.14'), updItem('0.1.0-rc.13')]);

    expect(app(UpdateAvailable::class)->banner()?->toArray())->toBe([
        'level' => 'info',
        'message' => 'Teme 0.1.0-rc.14 is available. This site runs 0.1.0-rc.13.',
        'actionLabel' => 'See the update',
        'actionUrl' => route('tools.updates.index'),
        'label' => '',
        'plugin' => null,
        'key' => '',
    ])
        ->and(app(UpdateAvailable::class)->banner()?->priority)->toBe(5)
        ->and($this->checks->forUser($this->admin)?->message)->toBe('Teme 0.1.0-rc.14 is available. This site runs 0.1.0-rc.13.');
});

it('warns when any newer release carries security fixes', function (): void {
    ($this->list)([
        updItem('0.1.0-rc.15'),
        updItem('0.1.0-rc.14', ['body' => 'Fix. <!-- teme-release {"format":1,"security":true,"maintenance":false} -->']),
    ]);

    $banner = app(UpdateAvailable::class)->banner();

    expect($banner?->level)->toBe('warning')
        ->and($banner?->priority)->toBe(60)
        ->and($banner?->message)->toBe('Teme 0.1.0-rc.15 is available, with security fixes. This site runs 0.1.0-rc.13.');
});

it('says nothing when the site runs the newest in its channel, on an untagged build, or to anyone without updates.manage', function (): void {
    // One list for the whole test: a second Http::fake() would not replace the first.
    ($this->list)([updItem('0.1.0-rc.14')]);

    config(['teme.version' => '0.1.0-rc.14']);
    expect(app(UpdateAvailable::class)->banner())->toBeNull();

    config(['teme.tagged' => false, 'teme.version' => '0.1.0']);
    expect(app(UpdateAvailable::class)->banner())->toBeNull();

    config(['teme.tagged' => true, 'teme.version' => '0.1.0-rc.13']);
    expect(app(UpdateAvailable::class)->banner())->not->toBeNull()
        ->and($this->checks->forUser(User::factory()->create()->assignRole('editor')))->toBeNull()
        ->and(app(UpdateAvailable::class)->ability())->toBe('updates.manage');
});

it('offers a pre-release only on the prerelease channel', function (): void {
    ($this->list)([updItem('0.1.0-rc.14')]);
    config(['teme.updates.channel' => 'stable']);

    expect(app(UpdateAvailable::class)->banner())->toBeNull();
});
