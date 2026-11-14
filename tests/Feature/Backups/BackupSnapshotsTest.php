<?php

declare(strict_types=1);

use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\ResticResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->made = TemporaryRepository::make();
    // One part of one run, as a backup files it, from standard input.
    $this->file = fn (array $flags): ResticResult => app(Restic::class)->run(
        $this->made->repository,
        ['backup', ...$flags, '--stdin', '--stdin-filename', 'part.txt'],
        input: 'a part',
    );
});

afterEach(function (): void {
    $this->made->delete();
});

it('files each part of a run under its tags and one host', function (): void {
    $flags = BackupSnapshots::partFlags('01jbackuprun0000000000000a', BackupSnapshots::MEDIA);
    $filed = ($this->file)($flags);
    $listed = app(Restic::class)->run($this->made->repository, ['snapshots'])->json();

    expect($flags)->toBe(['--host', 'teme', '--tag', 'teme', '--tag', 'teme-run:01jbackuprun0000000000000a', '--tag', 'teme-media'])
        ->and($filed->succeeded())->toBeTrue($filed->detail())
        ->and($listed[0]['hostname'] ?? null)->toBe('teme')
        ->and($listed[0]['tags'] ?? null)->toBe(['teme', 'teme-run:01jbackuprun0000000000000a', 'teme-media'])
        ->and(BackupSnapshots::PARTS)->toBe(['teme-database', 'teme-media', 'teme-app-key', 'teme-manifest']);
});

it('groups a place’s snapshots into runs, newest first, complete only with all four parts', function (): void {
    foreach (BackupSnapshots::PARTS as $part) {
        ($this->file)(BackupSnapshots::partFlags('01jwholerun000000000000000', $part));
    }

    ($this->file)(BackupSnapshots::partFlags('01jhalfrun0000000000000000', BackupSnapshots::DATABASE));
    ($this->file)(BackupSnapshots::partFlags('01jhalfrun0000000000000000', BackupSnapshots::MEDIA));
    // Teme's tag without a run, and a snapshot someone made with restic by hand.
    ($this->file)(['--host', 'teme', '--tag', 'teme']);
    ($this->file)(['--tag', 'their-own']);

    $runs = app(BackupSnapshots::class)->runs($this->made->repository);

    expect($runs)->toBeArray()
        ->and(array_map(fn ($run): string => $run->runId, $runs))->toBe(['', '01jhalfrun0000000000000000', '01jwholerun000000000000000'])
        ->and(array_map(fn ($run): bool => $run->complete(), $runs))->toBe([false, false, true])
        ->and(array_keys($runs[2]->parts))->toBe(BackupSnapshots::PARTS)
        ->and($runs[2]->ids)->toHaveCount(4)
        ->and(array_sum(array_map(fn ($run): int => count($run->ids), $runs)))->toBe(7)
        ->and($runs[1]->time->lessThan($runs[0]->time))->toBeTrue();
});

it('says restic’s failure when a place holds no backups', function (): void {
    $runs = app(BackupSnapshots::class)->runs(new ResticRepository(TemporaryRepository::freshFolder(), TemporaryRepository::KEY));

    expect($runs)->toBeInstanceOf(ResticResult::class)
        ->and($runs->outcome)->toBe(ResticOutcome::NoRepository);
});

it('writes a folder as restic writes it inside a snapshot', function (): void {
    expect(BackupSnapshots::pathInSnapshot('C:\Sites\Teme\storage\app\public\media'))->toBe('/C/Sites/Teme/storage/app/public/media')
        ->and(BackupSnapshots::pathInSnapshot('D:'))->toBe('/D')
        ->and(BackupSnapshots::pathInSnapshot('/srv/teme/storage/app/public/media'))->toBe('/srv/teme/storage/app/public/media');
});
