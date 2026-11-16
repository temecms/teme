<?php

declare(strict_types=1);

use App\Domain\Media\Models\MediaMove;
use App\Domain\Media\Operations\EndMediaMove;
use App\Domain\Media\Operations\Input\EndMediaMoveInput;
use App\Domain\Media\Operations\Input\RecordMediaMoveProgressInput;
use App\Domain\Media\Operations\Input\StartMediaMoveInput;
use App\Domain\Media\Operations\RecordMediaMoveProgress;
use App\Domain\Media\Operations\StartMediaMove;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function mmStart(array $changes = []): StartMediaMoveInput
{
    return new StartMediaMoveInput(...[
        'kind' => 'copy',
        'from' => 'server',
        'to' => 'bucket',
        'roles' => ['public', 'originals'],
        'scope' => 'all',
        'changedSince' => null,
        'deep' => false,
        'siteDisks' => ['public' => 'media', 'originals' => 'media-originals'],
        'itemsTotal' => 3,
        ...$changes,
    ]);
}

function mmProgress(string $runId, string $cursor, array $changes = []): RecordMediaMoveProgressInput
{
    return new RecordMediaMoveProgressInput(...[
        'runId' => $runId,
        'cursor' => $cursor,
        'itemsDone' => 1,
        'filesCopied' => 2,
        'filesPresent' => 1,
        'filesDeleted' => 0,
        'bytesCopied' => 100,
        'holes' => 0,
        'failures' => 0,
        'problems' => [],
        ...$changes,
    ]);
}

function mmOperator(): void
{
    app()->instance(Elevation::class, new OperatorElevation);
}

it('lets the operator start, record and end a run, the cursor only moving forward', function (): void {
    mmOperator();
    $id = app(StartMediaMove::class)->execute(mmStart());

    app(RecordMediaMoveProgress::class)->execute(mmProgress($id, '01JB0000000000000000000000'));
    app(RecordMediaMoveProgress::class)->execute(mmProgress($id, '01JA0000000000000000000000', ['bytesCopied' => 50]));
    app(EndMediaMove::class)->execute(new EndMediaMoveInput($id, 'finished', 'Verified.'));

    $run = MediaMove::query()->findOrFail($id);

    expect($run->state)->toBe('finished')
        ->and($run->cursor)->toBe('01JB0000000000000000000000')
        ->and($run->items_done)->toBe(2)
        ->and($run->files_copied)->toBe(4)
        ->and($run->files_present)->toBe(2)
        ->and($run->bytes_copied)->toBe(150)
        ->and($run->roles)->toBe(['originals', 'public'])
        ->and($run->site_disks)->toBe(['public' => 'media', 'originals' => 'media-originals'])
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->message)->toBe('Verified.');
});

it('keeps the first 1,000 problems while counting every failure', function (): void {
    mmOperator();
    $id = app(StartMediaMove::class)->execute(mmStart());
    $problem = fn (int $n): array => ['id' => sprintf('01JA%022d', $n), 'role' => 'public', 'path' => "x/{$n}.webp", 'reason' => 'size differs after copying'];

    app(RecordMediaMoveProgress::class)->execute(mmProgress($id, '01JA0000000000000000000001', ['failures' => 900, 'problems' => array_map($problem, range(1, 900))]));
    app(RecordMediaMoveProgress::class)->execute(mmProgress($id, '01JA0000000000000000000002', ['failures' => 300, 'problems' => array_map($problem, range(901, 1200))]));

    $run = MediaMove::query()->findOrFail($id);

    expect($run->failures)->toBe(1200)
        ->and($run->problems)->toHaveCount(1000)
        ->and($run->problems[999]['path'])->toBe('x/1000.webp');
});

it('writes nothing when anyone but the operator asks', function (Closure $as): void {
    $this->seed(RoleSeeder::class);
    mmOperator();
    $id = app(StartMediaMove::class)->execute(mmStart());
    app()->instance(Elevation::class, new DeniedElevation);
    $actorId = $as();
    $before = json_encode(DB::table('media_moves')->orderBy('id')->get());

    expect(fn () => app(StartMediaMove::class)->execute(mmStart(['to' => 'server', 'from' => 'bucket', 'actorId' => $actorId])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecordMediaMoveProgress::class)->execute(mmProgress($id, '01JZ0000000000000000000000', ['actorId' => $actorId])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EndMediaMove::class)->execute(new EndMediaMoveInput($id, 'finished', null, $actorId)))->toThrow(AuthorizationException::class)
        ->and(json_encode(DB::table('media_moves')->orderBy('id')->get()))->toBe($before);
})->with([
    'an administrator' => [fn (): string => (string) User::factory()->create()->assignRole('administrator')->id],
    'an editor' => [fn (): string => (string) User::factory()->create()->assignRole('editor')->id],
    'no one, without the operator' => [fn (): ?string => null],
]);

it('refuses a second run in the same direction while one runs, and resumes one by its ID', function (): void {
    mmOperator();
    $id = app(StartMediaMove::class)->execute(mmStart());

    expect(fn () => app(StartMediaMove::class)->execute(mmStart()))->toThrow(ValidationException::class)
        ->and(MediaMove::query()->count())->toBe(1);

    app(EndMediaMove::class)->execute(new EndMediaMoveInput($id, 'stopped', 'Stopped by the operator.'));
    $resumed = app(StartMediaMove::class)->execute(mmStart(['resumeId' => $id]));

    expect($resumed)->toBe($id)
        ->and(MediaMove::query()->findOrFail($id)->state)->toBe('running')
        ->and(MediaMove::query()->findOrFail($id)->finished_at)->toBeNull()
        ->and(MediaMove::query()->count())->toBe(1);
});

it('refuses to record a run that has ended', function (): void {
    mmOperator();
    $id = app(StartMediaMove::class)->execute(mmStart());
    app(EndMediaMove::class)->execute(new EndMediaMoveInput($id, 'finished', null));

    expect(fn () => app(RecordMediaMoveProgress::class)->execute(mmProgress($id, '01JZ0000000000000000000000')))->toThrow(ValidationException::class)
        ->and(fn () => app(EndMediaMove::class)->execute(new EndMediaMoveInput($id, 'stopped', null)))->toThrow(ValidationException::class)
        ->and(MediaMove::query()->findOrFail($id)->cursor)->toBeNull();
});

it('refuses a changed scope without its date, and a move to where it came from, in the table itself', function (array $changes): void {
    $row = [
        'id' => '01JA0000000000000000000000', 'kind' => 'copy', 'from_location' => 'server', 'to_location' => 'bucket',
        'roles' => '["originals","public"]', 'scope' => 'all', 'changed_since' => null, 'site_disks' => '{}',
        'state' => 'running', 'items_total' => 0, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::table('media_moves')->insert([...$row, ...$changes]))->toThrow(QueryException::class);
})->with([
    'changed, no date' => [['scope' => 'changed']],
    'all, with a date' => [['changed_since' => now()]],
    'from = to' => [['to_location' => 'server']],
    'an unknown kind' => [['kind' => 'sync']],
    'roles not a list' => [['roles' => '{}']],
]);
