<?php

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\Manifest;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticOutcome;
use App\Domain\Backups\Tools\ResticRepository;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Puts a backup's media back (I-2404): each media disk's folder made the
 * snapshot's exactly. Its targets are checked first, since a restore
 * deletes what the snapshot lacks.
 */
final readonly class MediaRestore
{
    public function __construct(private Restic $restic) {}

    /**
     * Makes each media folder the snapshot's exactly (I-2404): what it lacks
     * written, what it holds that the snapshot doesn't removed, a file
     * already right left alone. One restic call a folder.
     *
     * @param  list<array{role: string, from: string, to: string}>  $pairs  as pairs() checked them
     *
     * @throws RestoreStopped
     */
    public function restore(ResticRepository $repository, string $snapshotId, array $pairs, string $place): void
    {
        foreach ($pairs as $pair) {
            if (! is_dir($pair['to'])) {
                // An image's original is private (docs/architecture/media.md).
                mkdir($pair['to'], $pair['role'] === 'originals' ? 0700 : 0755, true);
            }

            $restored = $this->restic->run(
                $repository,
                ['restore', $snapshotId.':'.$pair['from'], '--target', $pair['to'], '--delete', '--overwrite', 'if-changed'],
                timeout: RestoreSite::WORK_SECONDS,
            );

            if (! $restored->succeeded()) {
                Log::warning("Backups: restoring the media at {$place}: {$restored->detail()}");

                throw new RestoreStopped($restored->outcome === ResticOutcome::Incomplete
                    ? "restic couldn’t restore some media files from {$place}. The log has restic’s own words."
                    : $restored->sentence($place));
            }
        }
    }

    /**
     * Each of this server's media folders, paired with the folder the backup
     * holds it in, and every problem with them, in words.
     *
     * @return array{pairs: list<array{role: string, from: string, to: string}>, problems: list<string>}
     */
    public static function pairs(Manifest $manifest): array
    {
        $there = (array) (((array) ($manifest->toArray()['media'] ?? []))['snapshot_roots'] ?? []);
        [$here, $problems] = [[], []];

        foreach (['originals' => (string) config('media.originals_disk'), 'public' => (string) config('media.disk')] as $role => $disk) {
            $adapter = Storage::disk($disk);

            if (! $adapter instanceof LocalFilesystemAdapter) {
                $problems[] = "The media disk “{$disk}” isn’t a folder on this server, so Teme can’t restore its files.";

                continue;
            }

            $problem = self::problemWith($disk, $adapter->path(''));

            if ($problem !== null) {
                $problems[] = $problem;

                continue;
            }

            $here[$role] = self::normal($adapter->path(''));
        }

        if ($problems !== []) {
            return ['pairs' => [], 'problems' => $problems];
        }

        $sharedHere = self::normal($here['originals']) === self::normal($here['public']);

        if ($sharedHere !== ((string) ($there['originals'] ?? '') === (string) ($there['public'] ?? ''))) {
            return ['pairs' => [], 'problems' => ['The two media disks share one folder here and had two on the backup’s server, or the other way round. Lay them out as the backup’s server did.']];
        }

        if (! $sharedHere && (self::inside($here['originals'], $here['public']) || self::inside($here['public'], $here['originals']))) {
            return ['pairs' => [], 'problems' => ['One media disk’s folder lies inside the other’s here. Give each a folder of its own.']];
        }

        $pairs = [];

        foreach ($sharedHere ? ['public'] : ['originals', 'public'] as $role) {
            $pairs[] = ['role' => $role, 'from' => (string) $there[$role], 'to' => $here[$role]];
        }

        return ['pairs' => $pairs, 'problems' => []];
    }

    /**
     * What makes a media disk's folder, as its adapter gives it, unsafe for
     * restic's --delete, in words; null when it's safe: no full path; a
     * folder that is or holds the site's own folders, another local disk's,
     * or a filesystem's root; or one among the site's code outside storage/.
     */
    public static function problemWith(string $disk, string $root): ?string
    {
        $written = str_replace('\\', '/', $root);

        if (preg_match('#^(/|[A-Za-z]:/)#', $written) !== 1) {
            return "The media disk “{$disk}” has no full path to a folder on this server. Give it one.";
        }

        $root = self::normal($written);

        if (self::holdsMore($root)) {
            return "The media disk “{$disk}” is kept in {$root}, which holds more than media: restoring it would delete the other files there. Give the media a folder of its own.";
        }

        // storage/ holds no code; Laravel's Storage::fake() keeps a test's disks there too.
        if (self::inside($root, base_path()) && ! self::inside($root, storage_path())) {
            return "The media disk “{$disk}” is kept in {$root}, among the site’s code, where a restore mustn’t delete. Keep the media under ".storage_path().' or outside the site.';
        }

        return null;
    }

    /**
     * Whether a media folder is, or holds, the site's own folders or another
     * local disk's: restic's --delete would remove their files.
     */
    private static function holdsMore(string $root): bool
    {
        foreach ([base_path(), storage_path(), storage_path('app'), storage_path('app/public'), storage_path('app/private'), public_path()] as $site) {
            if (self::inside($site, $root)) {
                return true;
            }
        }

        foreach ((array) config('filesystems.disks') as $name => $other) {
            if (in_array($name, [config('media.disk'), config('media.originals_disk')], true) || ! is_array($other) || ($other['driver'] ?? null) !== 'local') {
                continue;
            }

            if (self::inside((string) ($other['root'] ?? ''), $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $path is $folder or lies inside it, as written and through any link.
     */
    private static function inside(string $path, string $folder): bool
    {
        [$path, $folder] = [self::normal($path), self::normal($folder)];

        return $path !== '' && $folder !== '' && ($path === $folder || str_starts_with($path, rtrim($folder, '/').'/'));
    }

    private static function normal(string $path): string
    {
        $real = $path === '' ? false : realpath($path);
        $path = str_replace('\\', '/', $real === false ? $path : $real);

        // A filesystem's root keeps its slash: '/' and 'C:/' hold everything.
        return preg_match('#^([A-Za-z]:)?/$#', $path) === 1 ? $path : rtrim($path, '/');
    }
}
