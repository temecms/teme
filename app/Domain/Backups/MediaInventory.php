<?php

/**
 * What a backup holds of the media (I-2404): every file the moment's media
 * rows name, originals and variants, each checked on its disk by its
 * recorded path, never by listing a folder; every original's SHA-256 for
 * the manifest; and the folders restic backs up. A role whose disk is a
 * bucket is left to the bucket: its files are listed and counted, never
 * checked or backed up (I-3456).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Media\MediaFiles;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class MediaInventory
{
    /**
     * What the media part of a backup holds when both roles are in a bucket.
     *
     * @since 1.0.0
     */
    public const string IN_BUCKET_FILE = 'media-in-object-storage.json';

    /** The most missing files the manifest names; it counts them all. */
    public const int NAMED_MISSING = 1000;

    private int $items = 0;

    private int $files = 0;

    private int $bytes = 0;

    private int $variantFiles = 0;

    private int $variantBytes = 0;

    private int $missingCount = 0;

    private int $bucketFiles = 0;

    /** @var list<array{id: string, disk: string, path: string, bytes: int, sha256: string}> */
    private array $originals = [];

    /** @var list<array{disk: string, path: string}> */
    private array $missing = [];

    /** @var array<'originals'|'public', int> */
    private array $missingOn = ['originals' => 0, 'public' => 0];

    /** @var list<'originals'|'public'> */
    private readonly array $absent;

    /**
     * @param  array{originals: string|null, public: string|null}  $roots  null for a role in a bucket
     */
    private function __construct(private readonly array $roots)
    {
        // Seen before folders() makes them: a folder that isn't there is often a volume that isn't mounted.
        $this->absent = array_values(array_filter(['originals', 'public'], fn (string $role): bool => $roots[$role] !== null && ! is_dir($roots[$role])));
    }

    /**
     * The two media disks' folders on this server, by role, null for a role
     * whose disk is a bucket (I-3456). Any other disk that isn't a folder
     * here is refused rather than backed up as nothing (I-390).
     *
     * @return array{originals: string|null, public: string|null}
     *
     * @throws BackupRefused
     */
    public static function roots(): array
    {
        return [
            'originals' => self::root((string) config('media.originals_disk')),
            'public' => self::root((string) config('media.disk')),
        ];
    }

    /**
     * Reads every media row the moment sees, trashed ones too, since a
     * trashed item can be restored, and checks each file it names.
     *
     * @param  array{originals: string|null, public: string|null}  $roots
     */
    public static function take(DatabaseMoment $moment, array $roots): self
    {
        $inventory = new self($roots);
        $moment->eachMedia($inventory->add(...));

        return $inventory;
    }

    /**
     * The folders restic backs up, sorted, each made first if it doesn't
     * exist yet, so a site with no media still makes a whole backup; none
     * when both roles are in a bucket.
     *
     * @return list<string>
     */
    public function folders(): array
    {
        foreach (['originals' => 0700, 'public' => 0755] as $role => $mode) {
            if ($this->roots[$role] !== null && ! is_dir($this->roots[$role])) {
                mkdir($this->roots[$role], $mode, true);
            }
        }

        $folders = array_values(array_unique(array_filter([$this->roots['originals'], $this->roots['public']], is_string(...))));
        sort($folders);

        return $folders;
    }

    /**
     * How many files the rows name, originals and variants. Never named as a
     * folder's listing is (tests/Architecture/NoStorageListingTest.php).
     */
    public function fileCount(): int
    {
        return $this->files;
    }

    /**
     * Their bytes, as the rows record them.
     */
    public function byteCount(): int
    {
        return $this->bytes;
    }

    /**
     * How many of them are on a role in a bucket, left to the bucket.
     *
     * @since 1.0.0
     */
    public function bucketFileCount(): int
    {
        return $this->bucketFiles;
    }

    /**
     * Where each role's files were, as the manifest says it.
     *
     * @return array{public: 'server'|'bucket', originals: 'server'|'bucket'}
     *
     * @since 1.0.0
     */
    public function locations(): array
    {
        return [
            'public' => $this->roots['public'] === null ? 'bucket' : 'server',
            'originals' => $this->roots['originals'] === null ? 'bucket' : 'server',
        ];
    }

    /**
     * How many of them aren't on their disks.
     */
    public function missingCount(): int
    {
        return $this->missingCount;
    }

    /**
     * The first media folder that wasn't on this server when the inventory
     * looked, of those a missing file the rows name is on; null when none.
     */
    public function absentFolder(): ?string
    {
        foreach ($this->absent as $role) {
            if ($this->missingOn[$role] > 0 && $this->roots[$role] !== null) {
                return $this->roots[$role];
            }
        }

        return null;
    }

    /**
     * The first file the rows name that isn't on its disk, as its disk's
     * role, a colon and its path; null when none is missing.
     */
    public function firstMissing(): ?string
    {
        $first = $this->missing[0] ?? null;

        return $first === null ? null : $first['disk'].':'.$first['path'];
    }

    /**
     * The manifest's media part (I-2404).
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $roots = array_filter($this->roots, is_string(...));

        return [
            'items' => $this->items,
            'files' => $this->files,
            'bytes' => $this->bytes,
            'variants' => ['files' => $this->variantFiles, 'bytes' => $this->variantBytes],
            'locations' => $this->locations(),
            'roots' => $roots,
            'snapshot_roots' => array_map(BackupSnapshots::pathInSnapshot(...), $roots),
            'originals' => $this->originals,
            'missing' => ['count' => $this->missingCount, 'files' => $this->missing],
        ];
    }

    /**
     * @throws BackupRefused
     */
    private static function root(string $name): ?string
    {
        $disk = Storage::disk($name);

        if ($disk instanceof AwsS3V3Adapter) {
            return null;
        }

        if (! $disk instanceof LocalFilesystemAdapter) {
            throw new BackupRefused("The media disk “{$name}” isn’t a folder on this server, so Teme can’t back its files up.");
        }

        return rtrim($disk->path(''), '/\\');
    }

    /**
     * @param  object{id: string, kind: string, original_path: string, size_bytes: int|string, sha256: string|null, variants: string|null}  $row
     */
    private function add(object $row): void
    {
        $this->items++;

        foreach (MediaFiles::of($row) as $index => $file) {
            if ($index === 0) {
                $this->originals[] = ['id' => (string) $row->id, 'disk' => $file['role'], 'path' => $file['path'], 'bytes' => $file['bytes'], 'sha256' => (string) $file['sha256']];
            } else {
                $this->variantFiles++;
                $this->variantBytes += $file['bytes'];
            }

            $this->note($file['role'], $file['path'], $file['bytes']);
        }
    }

    /**
     * Counts one file, and notes it when it isn't on its disk, by its
     * recorded path (I-358).
     *
     * @param  'originals'|'public'  $disk
     */
    private function note(string $disk, string $path, int $bytes): void
    {
        $this->files++;
        $this->bytes += $bytes;

        // In a bucket: not checked here, file by file each night (I-3456).
        if ($this->roots[$disk] === null) {
            $this->bucketFiles++;

            return;
        }

        if (is_file($this->roots[$disk].DIRECTORY_SEPARATOR.$path)) {
            return;
        }

        $this->missingCount++;
        $this->missingOn[$disk]++;

        if (count($this->missing) < self::NAMED_MISSING) {
            $this->missing[] = ['disk' => $disk, 'path' => $path];
        }
    }
}
