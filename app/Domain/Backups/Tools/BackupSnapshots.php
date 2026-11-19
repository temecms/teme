<?php

/**
 * Teme's snapshots at one place, by run (I-362): a backup run is four restic
 * snapshots, one for each part, tagged teme and teme-run:{run}, under one
 * host name; a run is complete exactly when its manifest, written last, is
 * there. Teme never touches a snapshot without its tag.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use Carbon\CarbonImmutable;

final readonly class BackupSnapshots
{
    /** One host for every snapshot: a container's own name changes with each deployment, and restic finds the last media snapshot by host. */
    public const string HOST = 'teme';

    /** Every snapshot Teme makes carries it. */
    public const string TAG = 'teme';

    public const string RUN_TAG = 'teme-run:';

    public const string DATABASE = 'teme-database';

    public const string MEDIA = 'teme-media';

    public const string APP_KEY = 'teme-app-key';

    public const string MANIFEST = 'teme-manifest';

    /** @var list<string> the parts, in the order a run writes them */
    public const array PARTS = [self::DATABASE, self::MEDIA, self::APP_KEY, self::MANIFEST];

    /** The files read from standard input, named without a leading slash (I-2736). */
    public const string DUMP_FILE = 'teme.dump';

    public const string APP_KEY_FILE = 'app-key.json';

    public const string MANIFEST_FILE = 'manifest.json';

    public function __construct(private Restic $restic) {}

    /**
     * restic backup's flags that file one part of one run.
     *
     * @return list<string>
     */
    public static function partFlags(string $runId, string $part): array
    {
        return ['--host', self::HOST, '--tag', self::TAG, '--tag', self::RUN_TAG.$runId, '--tag', $part];
    }

    /**
     * A folder as restic writes it inside a snapshot: forward slashes, and a
     * Windows drive as a folder of its own, so C:\x is /C/x.
     */
    public static function pathInSnapshot(string $absolute): string
    {
        $path = str_replace('\\', '/', $absolute);

        return preg_match('#^([A-Za-z]):(/.*)?$#', $path, $drive) === 1 ? '/'.$drive[1].($drive[2] ?? '') : $path;
    }

    /**
     * Teme's runs at a place, newest first, or restic's failure. A snapshot
     * with Teme's tag but no run's is a run of its own with no ID, never
     * complete, so a prune forgets it.
     *
     * @return list<SnapshotRun>|ResticResult
     */
    public function runs(ResticRepository $repository): array|ResticResult
    {
        $listed = $this->restic->run($repository, ['snapshots', '--tag', self::TAG]);

        if (! $listed->succeeded()) {
            return $listed;
        }

        $snapshots = $listed->json();
        /** @var array<string, array{time: CarbonImmutable, parts: array<string, string>, ids: list<string>}> $runs */
        $runs = [];

        foreach (is_array($snapshots) ? $snapshots : [] as $snapshot) {
            if (! is_array($snapshot) || ! is_string($snapshot['id'] ?? null) || ! is_string($snapshot['time'] ?? null)) {
                continue;
            }

            $tags = array_values(array_filter((array) ($snapshot['tags'] ?? []), is_string(...)));
            $run = '';

            foreach ($tags as $tag) {
                if (str_starts_with($tag, self::RUN_TAG)) {
                    $run = substr($tag, strlen(self::RUN_TAG));
                }
            }

            // restic writes nanoseconds; PHP keeps microseconds.
            $time = CarbonImmutable::parse((string) preg_replace('/(\.\d{6})\d+/', '$1', $snapshot['time']));
            $runs[$run] ??= ['time' => $time, 'parts' => [], 'ids' => []];
            $runs[$run]['time'] = $time->lessThan($runs[$run]['time']) ? $time : $runs[$run]['time'];
            $runs[$run]['ids'][] = $snapshot['id'];

            foreach (array_intersect(self::PARTS, $tags) as $part) {
                $runs[$run]['parts'][$part] = $snapshot['id'];
            }
        }

        $list = [];

        foreach ($runs as $runId => $run) {
            $parts = array_intersect_key(array_flip(self::PARTS), $run['parts']);
            $list[] = new SnapshotRun((string) $runId, $run['time'], array_replace($parts, $run['parts']), $run['ids']);
        }

        usort($list, fn (SnapshotRun $a, SnapshotRun $b): int => [$b->time, $b->runId] <=> [$a->time, $a->runId]);

        return $list;
    }
}
