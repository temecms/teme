<?php

declare(strict_types=1);

use App\Domain\Backups\BackupKeyRole;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Models\BackupAlert;
use App\Domain\Backups\Notifications\BackupAlertNotice;
use App\Domain\Backups\Operations\Input\RaiseBackupAlertInput;
use App\Domain\Backups\Operations\RaiseBackupAlert;
use App\Domain\Backups\Queries\BackupAdministrators;
use App\Domain\Inbox\Models\InboxItem;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\VerifyDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['general' => ['timezone' => 'UTC', 'site_title' => 'Teme Test Site'], BackupSettings::GROUP => ['hour' => '02:00']] as $group => $overrides) {
        SettingsGroupRecord::query()->updateOrCreate(['group_key' => $group], ['overrides' => $overrides, 'version' => 1]);
        Cache::memo()->forget(SettingsRepository::cacheKey($group));
    }

    $this->seed(RoleSeeder::class);
    VerifyDatabase::unnamed();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'));
    app()->instance(Elevation::class, new OperatorElevation);
    $this->admins = [User::factory()->create()->assignRole('administrator'), User::factory()->create()->assignRole('administrator')];
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->suspended = User::factory()->create(['suspended_at' => now()])->assignRole('administrator');
    $this->unverified = User::factory()->unverified()->create()->assignRole('administrator');
    $place = BackupRecords::destination();
    BackupRecords::key($place, ['role' => BackupKeyRole::Recovery, 'label' => 'Recovery key', 'added_at' => now()->subDays(3)]);
    $this->raise = fn (?string $actorId = null): ?string => app(RaiseBackupAlert::class)->execute(new RaiseBackupAlertInput($actorId));
    $this->fail = fn (): mixed => BackupRecords::run(['state' => BackupRunState::Failed, 'message' => 'No place answered, so nothing was backed up.', 'started_at' => now(), 'ended_at' => now()]);
    $this->adminIds = collect($this->admins)->pluck('id')->sort()->values()->all();
});

it('puts an item in every administrator’s inbox beside each failure email, and in no one else’s', function (): void {
    ($this->fail)();
    ($this->raise)();

    $items = InboxItem::query()->orderBy('user_id')->get();

    expect($items->pluck('user_id')->all())->toBe($this->adminIds)
        ->and($items->pluck('kind')->unique()->values()->all())->toBe(['backup.failing'])
        ->and($items[0]->body)->toEqual([
            'title' => 'Backups are failing',
            'text' => 'The last backup failed: No place answered, so nothing was backed up.',
            'link' => ['route' => 'tools.backups.index', 'parameters' => [], 'ability' => 'backups.manage'],
        ])
        ->and($items[0]->read_at)->toBeNull();
});

it('says a reminder and a fix in their own words, an item for each email', function (): void {
    ($this->fail)();
    ($this->raise)();
    $this->travel(24)->hours();
    ($this->fail)();
    ($this->raise)();
    $this->travel(1)->minutes();
    BackupRecords::run(['started_at' => now(), 'ended_at' => now()]);
    ($this->raise)();

    $items = InboxItem::query()->where('user_id', $this->admins[0]->id)->orderBy('created_at')->get();

    expect($items->pluck('kind')->all())->toBe(['backup.failing', 'backup.reminder', 'backup.fixed'])
        ->and($items[1]->body['title'])->toBe('Backups are still failing')
        ->and($items[1]->body['text'])->toBe('The last backup failed: No place answered, so nothing was backed up.')
        ->and($items[2]->body['title'])->toBe('Backups are working again')
        ->and($items[2]->body['text'])->toBe('The newest backup is from 19 October 2026 at 10:01, and its checks passed.')
        ->and(BackupAlert::query()->count())->toBe(3);
});

it('sends nothing when no alert is due', function (): void {
    // A backup just made, so the verdict has nothing to alert about.
    BackupRecords::run(['started_at' => now(), 'ended_at' => now()]);

    expect(($this->raise)())->toBeNull()
        ->and(InboxItem::query()->count())->toBe(0);
});

it('tells exactly the people the email goes to: verified, not suspended, holding backups.manage', function (): void {
    ($this->fail)();
    ($this->raise)();

    expect(app(BackupAdministrators::class)->all()->pluck('id')->all())->toBe($this->adminIds)
        ->and(InboxItem::query()->orderBy('user_id')->pluck('user_id')->all())->toBe($this->adminIds);

    Notification::assertSentTo($this->admins, BackupAlertNotice::class);
    Notification::assertNotSentTo([$this->editor, $this->suspended, $this->unverified], BackupAlertNotice::class);
});

it('writes no inbox item when authorization fails', function (): void {
    ($this->fail)();
    app()->forgetInstance(Elevation::class);

    expect(fn () => ($this->raise)((string) $this->editor->id))->toThrow(AuthorizationException::class)
        ->and(InboxItem::query()->count())->toBe(0)
        ->and(BackupAlert::query()->count())->toBe(0);
});

it('opens Tools › Backups from the item, marking it read', function (): void {
    ($this->fail)();
    ($this->raise)();
    $item = InboxItem::query()->where('user_id', $this->admins[0]->id)->sole();

    $this->actingAs($this->admins[0])->post(route('inbox.open', $item->id))->assertRedirect(route('tools.backups.index'));

    expect($item->fresh()->read_at)->not->toBeNull();
});
