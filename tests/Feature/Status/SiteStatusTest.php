<?php

declare(strict_types=1);

use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusCheck;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
});

/**
 * A status check that answers what it is given, for someone holding the ability.
 */
function sstCheck(string $ability, ?StatusBanner $banner): StatusCheck
{
    return new class($ability, $banner) implements StatusCheck
    {
        public function __construct(private string $ability, private ?StatusBanner $banner) {}

        public function ability(): string
        {
            return $this->ability;
        }

        public function label(): string
        {
            return 'Testing';
        }

        public function banner(): ?StatusBanner
        {
            return $this->banner;
        }
    };
}

it('lists every banner a person may act on, the most pressing first, and keeps the most pressing as the one banner', function (): void {
    $checks = new StatusChecks;
    $checks->add(sstCheck('users.manage', new StatusBanner('warning', 'Backups have not run for a day.', null, null, 10)), 'core');
    $checks->add(sstCheck('users.manage', new StatusBanner('error', 'The theme cannot be used.', 'Choose a theme', '/admin/themes', 50)), 'core');
    $checks->add(sstCheck('users.manage', null), 'core');
    $checks->add(sstCheck('users.manage', new StatusBanner('info', 'A second warning, registered later.', null, null, 10)), 'core');

    expect(array_map(fn (StatusBanner $banner): string => $banner->message, $checks->bannersFor($this->admin)))
        ->toBe(['The theme cannot be used.', 'Backups have not run for a day.', 'A second warning, registered later.'])
        ->and($checks->forUser($this->admin)?->message)->toBe('The theme cannot be used.')
        ->and($checks->bannersFor($this->author))->toBe([])
        ->and($checks->forUser($this->author))->toBeNull();
});

it('shares every banner as the site status, the theme that cannot be used first, and nothing to someone who cannot fix it', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('broken');

    $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('siteStatus.0.level', 'error')
        ->where('siteStatus.0.message', fn (string $message): bool => str_contains($message, '"broken"'))
        ->where('siteStatus.0.message', fn (string $message): bool => str_contains($message, '"broken"')));

    $this->actingAs($this->author)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('siteStatus', [])
        ->where('siteStatus', []));
});
