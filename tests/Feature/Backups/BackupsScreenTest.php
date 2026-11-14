<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('draws Tools › Backups for an administrator from the tables alone, running nothing', function (): void {
    Process::fake();

    $this->actingAs($this->admin)->get('/admin/tools/backups')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tools/backups/index')
            ->where('setup.state', 'none')
            ->where('running', null)
            ->where('schedule', null)
            ->where('places', [])
            ->where('pending', null));

    Process::assertNothingRan();
});

it('draws a set-up site’s places, newest backup, schedule and keys', function (): void {
    ScreenRecords::settings(['general' => ['timezone' => 'UTC']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'UTC'));
    $folder = ScreenRecords::place();
    ScreenRecords::place('Off-site', ['kind' => BackupDestinationKind::S3, 'created_at' => now()->addSecond()]);
    ScreenRecords::backup($folder, '2026-10-20 02:05', figures: ['seconds' => 300]);

    $this->actingAs($this->admin)->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page
            ->where('setup.state', 'set-up')
            ->where('firstBackup', null)
            ->where('newest.took', '5 minutes')
            ->has('places', 2)
            ->where('places.1.where', 'https://s3.example.com/teme-backups/site')
            ->has('keys', 1)
            ->where('schedule.next', 'Next backup tomorrow at 02:00.')
            ->where('offServerAdvice', false));
});

it('refuses Tools › Backups to everyone but an administrator', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    $this->actingAs($person)->get('/admin/tools/backups')->assertForbidden();
})->with(['editor', 'author', 'contributor']);

it('leads Settings’ address for the backups group to Tools › Backups', function (): void {
    expect(BackupSettings::SCREEN)->toBe('tools.backups.index');

    $this->actingAs($this->admin)->get('/admin/settings/backups')->assertRedirect(route('tools.backups.index'));
});

it('lists Backups in the hub for an administrator only', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($this->admin)->get('/admin/tools')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tools.2.key', 'backups')
            ->where('tools.2.icon', 'archive')
            ->where('tools.2.href', '/admin/tools/backups'));

    $this->actingAs($editor)->get('/admin/tools')
        ->assertInertia(fn (Assert $page) => $page->where('tools', fn ($tools): bool => ! collect($tools)->contains('key', 'backups')));
});
