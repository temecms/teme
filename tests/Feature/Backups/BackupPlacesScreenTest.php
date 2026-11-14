<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\Models\BackupDestination;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Backups\ReadyBackups;
use Tests\Fixtures\Backups\ScreenRecords;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed());
});

it('tests a place with the real restic, and says what it found in a toast', function (): void {
    $ready = ReadyBackups::atFolder();

    try {
        $ready->place->forceFill(['last_answered_at' => null])->save();

        ($this->as)()->post("/admin/tools/backups/places/{$ready->place->id}/test")
            ->assertRedirect(route('tools.backups.index'))
            ->assertInertiaFlash('toast.type', 'success');

        expect($ready->place->fresh()->state)->toBe(BackupDestinationState::Ready)
            ->and($ready->place->fresh()->last_answered_at)->not->toBeNull();
    } finally {
        $ready->delete();
    }
});

it('marks a place failing, in words, when its backups are gone', function (): void {
    $ready = ReadyBackups::atFolder();
    TemporaryRepository::remove($ready->folder);

    ($this->as)()->post("/admin/tools/backups/places/{$ready->place->id}/test")
        ->assertInertiaFlash('toast.type', 'error');

    expect($ready->place->fresh()->state)->toBe(BackupDestinationState::Failing)
        ->and($ready->place->fresh()->last_error)->not->toBeNull();
});

it('removes a place, saying its backups stay where they are', function (): void {
    ScreenRecords::place();
    $second = ScreenRecords::place('Old disk', ['created_at' => now()->addSecond()]);

    ($this->as)()->delete("/admin/tools/backups/places/{$second->id}")
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'Removed Old disk. Its backups are still there.');

    expect($second->fresh()->state)->toBe(BackupDestinationState::Removed);
});

it('refuses to remove the only place, in an error toast, changing nothing', function (): void {
    $place = ScreenRecords::place();

    ($this->as)()->delete("/admin/tools/backups/places/{$place->id}")
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'This server is the only place backups are kept. Add another place before removing it.');

    expect($place->fresh()->state)->toBe(BackupDestinationState::Ready);
});

it('removes the place of an unfinished setup, to start again', function (): void {
    $place = ScreenRecords::place(recoveryLabel: null);

    ($this->as)()->delete("/admin/tools/backups/places/{$place->id}")
        ->assertInertiaFlash('toast.message', 'Removed This server. Set backups up again when you’re ready.');

    expect(BackupDestination::query()->where('state', '<>', 'removed')->count())->toBe(0);
});

it('asks for the password before testing or removing, and refuses everyone but an administrator', function (): void {
    $place = ScreenRecords::place();
    ScreenRecords::place('Old disk', ['created_at' => now()->addSecond()]);

    $this->actingAs($this->admin)->delete("/admin/tools/backups/places/{$place->id}")->assertRedirect(route('password.confirm'));
    $this->actingAs(User::factory()->create()->assignRole('editor'))->withSession(scConfirmed())
        ->post("/admin/tools/backups/places/{$place->id}/test")->assertForbidden();

    expect($place->fresh()->state)->toBe(BackupDestinationState::Ready)
        ->and(DB::table('security_events')->where('type', 'backup.destination_removed')->count())->toBe(0);
});
