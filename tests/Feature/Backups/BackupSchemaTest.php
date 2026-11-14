<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\BackupDestinationState;
use App\Domain\Backups\BackupRunKind;
use App\Domain\Backups\BackupRunState;
use App\Domain\Backups\BackupTrigger;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Backups\BackupRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The constraint that refused $change, or null when nothing did. A
    // savepoint keeps the test's own transaction usable after a refusal.
    // PostgreSQL tests a table's CHECK constraints in name order, so each
    // refusal's row passes every check whose name sorts before the one under test.
    $this->refusedBy = function (Closure $change): ?string {
        try {
            DB::transaction($change);
        } catch (QueryException $refused) {
            return preg_match('/constraint "([^"]+)"/', $refused->getMessage(), $match) === 1 ? $match[1] : 'not-null';
        }

        return null;
    };
});

it('keeps a place\'s credentials encrypted as JSON, out of its array and its JSON', function (): void {
    $place = BackupRecords::destination(['kind' => 's3', 'name' => 'Off site']);
    $stored = (string) DB::table('backup_destinations')->where('id', $place->id)->value('credentials');

    expect($stored)->not->toContain('test-secret')
        // encryptString() of JSON, never serialize() (rule 4): it opens as JSON text.
        ->and(json_decode(Crypt::decryptString($stored), true))->toBe(['access_key_id' => 'AKIATEST', 'secret_access_key' => 'test-secret'])
        ->and($place->fresh()->credentials)->toBe(['access_key_id' => 'AKIATEST', 'secret_access_key' => 'test-secret'])
        ->and($place->fresh()->toArray())->not->toHaveKey('credentials')
        ->and($place->fresh()->toJson())->not->toContain('test-secret');
});

it('holds a place\'s kind, the keys its location needs, and whether it is off the server', function (): void {
    expect(($this->refusedBy)(fn () => BackupRecords::destination(['kind' => 'folder', 'location' => ['path' => '/srv/backups']])))->toBeNull()
        ->and(($this->refusedBy)(fn () => DB::table('backup_destinations')->where('kind', 'folder')->update(['kind' => 'ftp', 'credentials' => 'x'])))->toBe('backup_destinations_kind_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['location' => ['folder' => '/srv']])))->toBe('backup_destinations_location_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['location' => ['path' => '']])))->toBe('backup_destinations_location_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['kind' => 's3', 'location' => ['endpoint' => 'https://s3.example.com']])))->toBe('backup_destinations_location_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['kind' => 'sftp', 'location' => ['host' => 'backups.example.com', 'path' => '/srv']])))->toBe('backup_destinations_location_check')
        ->and(($this->refusedBy)(fn () => DB::statement("UPDATE backup_destinations SET location = '[]'::jsonb")))->toBe('backup_destinations_location_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['off_server' => true])))->toBe('backup_destinations_off_server_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['kind' => 'sftp', 'off_server' => false])))->toBe('backup_destinations_off_server_check');
});

it('keeps no credentials for a folder or a removed place, and needs them for any other', function (): void {
    $place = BackupRecords::destination(['kind' => 's3']);
    // Always from the database: a refused update leaves its values on the model in memory.
    $update = fn (array $values): ?string => ($this->refusedBy)(fn () => $place->fresh()->update($values));

    expect(($this->refusedBy)(fn () => BackupRecords::destination(['credentials' => ['key' => 'x']])))->toBe('backup_destinations_credentials_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['kind' => 's3', 'credentials' => null])))->toBe('backup_destinations_credentials_check')
        ->and($update(['state' => BackupDestinationState::Removed, 'removed_at' => now()]))->toBe('backup_destinations_credentials_check')
        ->and($update(['state' => BackupDestinationState::Removed, 'removed_at' => now(), 'credentials' => null]))->toBeNull();
});

it('marks a removed place with when, and a failing one with its error', function (): void {
    $place = BackupRecords::destination();
    $someone = User::factory()->create();
    $update = fn (array $values): ?string => ($this->refusedBy)(fn () => $place->fresh()->update($values));

    expect($update(['state' => BackupDestinationState::Removed]))->toBe('backup_destinations_removed_check')
        ->and($update(['removed_by' => $someone->id]))->toBe('backup_destinations_removed_check')
        ->and($update(['state' => BackupDestinationState::Failing]))->toBe('backup_destinations_error_check')
        ->and($update(['last_error' => 'It didn’t answer.']))->toBe('backup_destinations_error_check')
        ->and($update(['state' => BackupDestinationState::Failing, 'last_error' => 'It didn’t answer.']))->toBeNull()
        ->and($update(['state' => BackupDestinationState::Removed, 'last_error' => null, 'removed_at' => now(), 'removed_by' => $someone->id]))->toBeNull();
});

it('takes each name and each repository once among the places in use, and again once one is removed', function (): void {
    $first = BackupRecords::destination(['name' => 'On this server', 'repository_id' => str_repeat('b', 64)]);

    expect(($this->refusedBy)(fn () => BackupRecords::destination(['name' => 'ON THIS SERVER'])))->toBe('backup_destinations_name_unique')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['name' => 'Another', 'repository_id' => str_repeat('b', 64)])))->toBe('backup_destinations_repository_unique')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['name' => '   '])))->toBe('backup_destinations_name_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::destination(['name' => 'Short', 'repository_id' => 'abc123'])))->toBe('backup_destinations_repository_check');

    $first->update(['state' => BackupDestinationState::Removed, 'removed_at' => now()]);

    expect(($this->refusedBy)(fn () => BackupRecords::destination(['name' => 'On this server', 'repository_id' => str_repeat('b', 64)])))->toBeNull();
});

it('ends a run exactly when it is no longer running, never before it started', function (): void {
    expect(($this->refusedBy)(fn () => BackupRecords::run(['state' => BackupRunState::Running, 'ended_at' => now()])))->toBe('backup_runs_ended_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::run(['state' => BackupRunState::Finished, 'ended_at' => null])))->toBe('backup_runs_ended_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::run(['started_at' => now(), 'ended_at' => now()->subMinute()])))->toBe('backup_runs_ended_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::run(['state' => BackupRunState::Running, 'ended_at' => null])))->toBeNull();
});

it('takes only its kinds, its states and what started it, and a person only for a person\'s run', function (): void {
    $person = User::factory()->create();
    $theirs = BackupRecords::run(['started_by' => BackupTrigger::Person, 'actor_id' => $person->id]);
    // Values no enum holds go in through the query builder, past the casts.
    $scheduled = DB::table('backup_runs')->where('id', BackupRecords::run()->id);

    expect(($this->refusedBy)(fn () => (clone $scheduled)->update(['kind' => 'sync'])))->toBe('backup_runs_kind_check')
        ->and(($this->refusedBy)(fn () => (clone $scheduled)->update(['state' => 'stopped'])))->toBe('backup_runs_state_check')
        ->and(($this->refusedBy)(fn () => (clone $scheduled)->update(['started_by' => 'cron'])))->toBe('backup_runs_started_by_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::run(['started_by' => BackupTrigger::Schedule, 'actor_id' => $person->id])))->toBe('backup_runs_actor_check');

    // A person's run outlives them, with no one named.
    $person->delete();

    expect($theirs->fresh()->actor_id)->toBeNull()
        ->and(array_map(fn (BackupRunKind $kind): string => $kind->value, BackupRunKind::cases()))
        ->toBe(['backup', 'copy', 'check', 'read-back', 'test-restore', 'prune', 'restore']);
});

it('keeps a run\'s figures as a JSON object, {} when empty, and a snapshot only as restic\'s ID', function (): void {
    $run = BackupRecords::run(['figures' => []]);

    expect(DB::scalar('SELECT jsonb_typeof(figures) FROM backup_runs WHERE id = ?', [$run->id]))->toBe('object')
        ->and($run->fresh()->figures)->toBe([])
        ->and(($this->refusedBy)(fn () => BackupRecords::run(['snapshot_id' => 'abc'])))->toBe('backup_runs_snapshot_check')
        ->and(($this->refusedBy)(fn () => DB::statement("UPDATE backup_runs SET figures = '[]'::jsonb")))->toBe('backup_runs_figures_check')
        ->and(($this->refusedBy)(fn () => DB::table('backup_runs')->where('id', $run->id)->update(['parent_id' => $run->id])))->toBe('backup_runs_parent_check');
});

it('ties a copy to its backup and its place', function (): void {
    $place = BackupRecords::destination(['kind' => 's3']);
    $backup = BackupRecords::run(['snapshot_id' => str_repeat('c', 64), 'figures' => ['files' => 12]]);
    $copy = BackupRecords::run(['kind' => BackupRunKind::Copy, 'parent_id' => $backup->id, 'destination_id' => $place->id]);

    expect($copy->parent->is($backup))->toBeTrue()
        ->and($copy->destination->is($place))->toBeTrue()
        ->and($place->runs()->pluck('id')->all())->toBe([$copy->id])
        ->and($backup->fresh()->figures)->toBe(['files' => 12])
        ->and($backup->fresh()->kind)->toBe(BackupRunKind::Backup)
        ->and($place->fresh()->kind)->toBe(BackupDestinationKind::S3)
        ->and($place->fresh()->kind->isOffServer())->toBeTrue();
});

it('keeps one machine key in use per place, each restic key once, and never the key itself', function (): void {
    $place = BackupRecords::destination();
    $machine = BackupRecords::key($place, ['role' => 'machine']);
    BackupRecords::key($place, ['role' => 'recovery', 'restic_key_id' => str_repeat('d', 64)]);

    $someone = User::factory()->create();

    expect(($this->refusedBy)(fn () => BackupRecords::key($place, ['role' => 'machine', 'restic_key_id' => str_repeat('e', 64)])))->toBe('backup_keys_one_machine')
        ->and(($this->refusedBy)(fn () => BackupRecords::key($place, ['role' => 'recovery', 'restic_key_id' => $machine->restic_key_id])))->toBe('backup_keys_destination_id_restic_key_id_unique')
        ->and(($this->refusedBy)(fn () => DB::table('backup_keys')->where('id', $machine->id)->update(['role' => 'spare'])))->toBe('backup_keys_role_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::key($place, ['role' => 'recovery', 'restic_key_id' => 'short'])))->toBe('backup_keys_restic_key_id_check')
        ->and(($this->refusedBy)(fn () => BackupRecords::key($place, ['role' => 'recovery', 'label' => ' ', 'restic_key_id' => str_repeat('f', 64)])))->toBe('backup_keys_label_check')
        ->and(($this->refusedBy)(fn () => $machine->fresh()->update(['removed_by' => $someone->id])))->toBe('backup_keys_removed_check');

    $machine->fresh()->update(['removed_at' => now(), 'removed_by' => $someone->id]);

    expect(($this->refusedBy)(fn () => BackupRecords::key($place, ['role' => 'machine', 'restic_key_id' => str_repeat('e', 64)])))->toBeNull()
        ->and($place->keys()->count())->toBe(3)
        ->and(Schema::getColumnListing('backup_keys'))->toEqualCanonicalizing([
            'id', 'destination_id', 'restic_key_id', 'role', 'label', 'added_by', 'added_at', 'removed_by', 'removed_at',
        ]);
});

it('never deletes a place that has runs or keys', function (): void {
    $place = BackupRecords::destination();
    BackupRecords::key($place);
    $other = BackupRecords::destination(['name' => 'Second', 'repository_id' => str_repeat('9', 64)]);
    BackupRecords::run(['destination_id' => $other->id]);

    expect(($this->refusedBy)(fn () => $place->delete()))->toBe('backup_keys_destination_id_foreign')
        ->and(($this->refusedBy)(fn () => $other->delete()))->toBe('backup_runs_destination_id_foreign');
});
