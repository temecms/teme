<?php

declare(strict_types=1);

use App\Domain\Privacy\Operations\Input\PrunePrivacyRequestsInput;
use App\Domain\Privacy\Operations\PrunePrivacyRequests;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    app()->instance(Elevation::class, new OperatorElevation);

    // A request row as a run leaves it, $days days after it ended (or was made, while open).
    $this->row = function (int $days, array $changes = []): string {
        $id = strtolower((string) Str::ulid());
        $status = $changes['status'] ?? 'done';
        $user = User::factory()->create();

        DB::table('privacy_requests')->insert([
            'id' => $id, 'kind' => 'export', 'email' => "person-{$id}@example.com", 'email_hash' => str_repeat('a', 63).dechex(random_int(0, 15)),
            'user_id' => $user->id, 'note' => 'A note.', 'status' => $status, 'results' => '[]',
            'completed_at' => in_array($status, ['done', 'failed'], true) ? now()->subDays($days) : null,
            'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
            ...$changes,
        ]);

        return $id;
    };
    $this->prune = fn (?string $actorId = null): array => app(PrunePrivacyRequests::class)->execute(new PrunePrivacyRequestsInput($actorId));
    $this->get = fn (string $id): ?object => DB::table('privacy_requests')->where('id', $id)->first();
});

it('stops a request left unfinished for more than 7 days, deleting its page files, and leaves a younger one', function (): void {
    $results = json_encode([['key' => 'comments', 'label' => 'Comments', 'page' => 2, 'done' => false, 'found' => 3]]);
    $old = ($this->row)(8, ['status' => 'running', 'results' => $results, 'email_hash' => str_repeat('b', 64)]);
    $young = ($this->row)(6, ['status' => 'running', 'results' => $results, 'email_hash' => str_repeat('c', 64)]);
    foreach ([$old, $young] as $id) {
        Storage::disk('local')->put("privacy/{$id}-comments-1.json", '[]');
        Storage::disk('local')->put("privacy/{$id}-comments-2.json", '[]');
    }

    $counts = ($this->prune)();

    expect(($this->get)($old)->status)->toBe('failed')
        ->and(($this->get)($old)->failure)->toBe('Not finished within 7 days.')
        ->and(($this->get)($old)->completed_at)->not->toBeNull()
        ->and(($this->get)($young)->status)->toBe('running')
        ->and(Storage::disk('local')->allFiles())->toEqualCanonicalizing(["privacy/{$young}-comments-1.json", "privacy/{$young}-comments-2.json"])
        ->and($counts['stopped'])->toBe(1);
});

it('deletes an export file 8 days after it ended and clears its path, and keeps one 6 days old', function (): void {
    $old = ($this->row)(8, ['export_path' => 'privacy/old.json']);
    $young = ($this->row)(6, ['export_path' => 'privacy/young.json']);
    Storage::disk('local')->put('privacy/old.json', '{}');
    Storage::disk('local')->put('privacy/young.json', '{}');

    $counts = ($this->prune)();

    expect(($this->get)($old)->export_path)->toBeNull()
        ->and(($this->get)($young)->export_path)->toBe('privacy/young.json')
        ->and(Storage::disk('local')->allFiles())->toBe(['privacy/young.json'])
        ->and($counts['files'])->toBe(1);
});

it('deletes every file of an export 8 days after it ended, whatever its state, and none of one 6 days old', function (string $status): void {
    $results = json_encode([['key' => 'comments', 'label' => 'Comments', 'page' => 2, 'done' => $status === 'done', 'found' => 3]]);
    $old = ($this->row)(8, ['status' => $status, 'results' => $results, 'email_hash' => str_repeat('b', 64)]);
    $young = ($this->row)(6, ['status' => $status, 'results' => $results, 'email_hash' => str_repeat('c', 64)]);
    // A file the run's last step wrote, and page files a killed after-commit step left.
    foreach ([$old, $young] as $id) {
        Storage::disk('local')->put("privacy/{$id}.json", '{}');
        Storage::disk('local')->put("privacy/{$id}-comments-1.json", '[]');
        Storage::disk('local')->put("privacy/{$id}-comments-2.json", '[]');
    }

    ($this->prune)();

    expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing($status === 'failed' ? [] : [
        "privacy/{$young}.json", "privacy/{$young}-comments-1.json", "privacy/{$young}-comments-2.json",
    ]);
})->with(['done' => 'done', 'stopped while running' => 'running', 'stopped while pending' => 'pending', 'failed' => 'failed']);

it('deletes every file of a failed export at once', function (): void {
    $results = json_encode([['key' => 'comments', 'label' => 'Comments', 'page' => 1, 'done' => false, 'found' => 1]]);
    $failed = ($this->row)(0, ['status' => 'failed', 'results' => $results, 'failure' => 'It broke.']);
    Storage::disk('local')->put("privacy/{$failed}.json", '{}');
    Storage::disk('local')->put("privacy/{$failed}-comments-1.json", '[]');

    ($this->prune)();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('clears the address and the account 31 days after, keeping the hash, and keeps both at 29 days', function (): void {
    $old = ($this->row)(31);
    $young = ($this->row)(29);
    $hash = ($this->get)($old)->email_hash;

    $counts = ($this->prune)();

    expect(($this->get)($old)->email)->toBeNull()
        ->and(($this->get)($old)->user_id)->toBeNull()
        ->and(($this->get)($old)->email_hash)->toBe($hash)
        ->and(($this->get)($young)->email)->not->toBeNull()
        ->and(($this->get)($young)->user_id)->not->toBeNull()
        ->and($counts['hashed'])->toBe(1);
});

it('deletes a record 366 days after with an abandoned export’s page files, and keeps one 364 days old', function (): void {
    $results = json_encode([['key' => 'comments', 'label' => 'Comments', 'page' => 1, 'done' => false, 'found' => 1]]);
    $old = ($this->row)(366, ['status' => 'failed', 'failure' => 'It broke.', 'results' => $results]);
    $young = ($this->row)(364);
    Storage::disk('local')->put("privacy/{$old}-comments-1.json", '[]');

    $counts = ($this->prune)();

    expect(($this->get)($old))->toBeNull()
        ->and(($this->get)($young))->not->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($counts['deleted'])->toBe(1);
});

it('ages an unfinished request from when it was made, not from its last step', function (): void {
    $open = ($this->row)(8, ['status' => 'pending', 'email_hash' => str_repeat('d', 64), 'updated_at' => now()]);

    ($this->prune)();

    expect(($this->get)($open)->status)->toBe('failed');
});

it('takes a file already gone as no error', function (): void {
    $old = ($this->row)(8, ['export_path' => 'privacy/gone.json']);

    ($this->prune)();

    expect(($this->get)($old)->export_path)->toBeNull();
});

it('writes nothing when a person runs it', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $old = ($this->row)(366);
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => ($this->prune)((string) $admin->id))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->prune)())->toThrow(AuthorizationException::class);
    expect(($this->get)($old))->not->toBeNull();
});

it('prunes from the console, saying what it did, every day', function (): void {
    ($this->row)(366);

    $this->artisan('teme:privacy:prune')
        ->expectsOutputToContain('Deleted 0 export file(s), cleared 1 address(es), and deleted 1 old request(s).')
        ->assertExitCode(0);

    $events = array_values(array_filter(app(Schedule::class)->events(), fn (Event $event): bool => str_contains((string) $event->command, 'teme:privacy:prune')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 0 * * *');
});
