<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\Queries\BackupsScreen;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->place = ScreenRecords::place('This server', ['paused_at' => now()]);
    ScreenRecords::backup($this->place, now()->subHours(3)->format('Y-m-d H:i'));
    $this->as = fn () => $this->actingAs($this->admin)->withSession(scConfirmed());
});

it('leads with the pause while a restore’s places wait for an answer', function (): void {
    expect(app(BackupsScreen::class)->setupState())->toBe(['state' => 'paused', 'placeId' => null, 'placeName' => null]);

    ($this->as)()->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page->where('setup.state', 'paused'));
});

it('marks each paused place, and offers no schedule while paused', function (): void {
    ($this->as)()->get('/admin/tools/backups')
        ->assertInertia(fn (Assert $page) => $page
            ->where('places.0.state', 'paused')
            ->where('places.0.stateLabel', 'Paused since the restore')
            ->where('schedule', null));
});

it('resumes backups when this server is now the site', function (): void {
    ($this->as)()->post('/admin/tools/backups/restored', ['answer' => 'site'])
        ->assertRedirect(route('tools.backups.index'))
        ->assertInertiaFlash('toast.message', 'Backups resume at This server.');

    expect($this->place->fresh()->paused_at)->toBeNull();
});

it('removes the places when this is a copy, saying their backups stay', function (): void {
    ($this->as)()->post('/admin/tools/backups/restored', ['answer' => 'copy'])
        ->assertInertiaFlash('toast.message', 'Removed This server from this site’s backup places. Their backups stay where they are.');

    expect($this->place->fresh()->state)->toBe(BackupDestinationState::Removed);

    ($this->as)()->get('/admin/tools/backups')->assertInertia(fn (Assert $page) => $page->where('setup.state', 'none'));
});

it('says so when nothing is paused', function (): void {
    $this->place->forceFill(['paused_at' => null])->save();

    ($this->as)()->post('/admin/tools/backups/restored', ['answer' => 'site'])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Nothing is paused: backups already run from this server.');
});

it('refuses an answer that is neither', function (): void {
    ($this->as)()->post('/admin/tools/backups/restored', ['answer' => 'maybe'])
        ->assertSessionHasErrors(['answer' => 'Answer site or copy.']);

    expect($this->place->fresh()->paused_at)->not->toBeNull();
});

it('asks for the password, and refuses everyone but an administrator, writing nothing', function (): void {
    $this->actingAs($this->admin)->post('/admin/tools/backups/restored', ['answer' => 'site'])->assertRedirect(route('password.confirm'));
    $this->actingAs(User::factory()->create()->assignRole('editor'))->withSession(scConfirmed())
        ->post('/admin/tools/backups/restored', ['answer' => 'site'])->assertForbidden();

    expect($this->place->fresh()->paused_at)->not->toBeNull()
        ->and(DB::table('security_events')->where('type', 'backup.destination_changed')->count())->toBe(0);
});
