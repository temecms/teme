<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\SnapshotRun;
use Closure;
use RuntimeException;

/**
 * A run's manifest replaced by a changed copy, with the run's own tags, so
 * a check can be shown a backup whose manifest and contents disagree. The
 * old manifest's snapshot is forgotten; the run stays whole.
 */
final class AlteredManifest
{
    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    public static function replace(ResticRepository $repository, SnapshotRun $run, Closure $change): void
    {
        $restic = app(Restic::class);
        $read = $restic->run($repository, ['dump', $run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE]);
        /** @var array<string, mixed> $manifest */
        $manifest = (array) $read->json();
        $forgot = $restic->run($repository, ['forget', $run->parts[BackupSnapshots::MANIFEST]]);
        $written = $restic->run(
            $repository,
            ['backup', ...BackupSnapshots::partFlags($run->runId, BackupSnapshots::MANIFEST), '--stdin', '--stdin-filename', BackupSnapshots::MANIFEST_FILE],
            input: json_encode($change($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        if (! $read->succeeded() || ! $forgot->succeeded() || ! $written->succeeded()) {
            throw new RuntimeException('restic couldn’t replace a test manifest: '.$read->detail().$forgot->detail().$written->detail());
        }
    }
}
