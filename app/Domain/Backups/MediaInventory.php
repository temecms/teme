<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Tools\BackupSnapshots;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * What a backup holds of the media (I-2404): every file the moment's media
 * rows name, originals and variants, each checked on its disk by its
 * recorded path, never by listing a folder; every original's SHA-256 for
 * the manifest; and the two disks' folders restic backs up.
 */
final class MediaInventory
{
    /** The most missing files the manifest names; it counts them all. */
    public const int NAMED_MISSING = 1000;

    private int $items = 0;

    private int $files = 0;

    private int $bytes = 0;

    private int $variantFiles = 0;

    private int $variantBytes = 0;

    private int $missingCount = 0;

    /** @var list<array{id: string, disk: string, path: string, bytes: int, sha256: string}> */
    private array $originals = [];

    /** @var list<array{disk: string, path: string}> */
    private array $missing = [];

    /** @var array<'originals'|'public', int> */
    private array $missingOn = ['originals' => 0, 'public' => 0];

    /** @var list<'originals'|'public'> */
    private readonly array $absent;

    /**
     * @param  array{originals: string, public: string}  $roots
     */
    private function __construct(private readonly array $roots)
    {
        // Seen before folders() makes them: a folder that isn't there is often a volume that isn't mounted.
        $this->absent = array_values(array_filter(['originals', 'public'], fn (string $role): bool => ! is_dir($roots[$role])));
    }

    /**
     * The two media disks' folders on this server, by role. A disk that
     * isn't a folder here, such as S3, is refused rather than backed up as
     * nothing (I-390).
     *
     * @return array{originals: string, public: string}
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
     * @param  array{originals: string, public: string}  $roots
     */
    public static function take(DatabaseMoment $moment, array $roots): self
    {
        $inventory = new self($roots);
        $moment->eachMedia($inventory->add(...));

        return $inventory;
    }

    /**
     * The folders restic backs up, sorted, each made first if it doesn't
     * exist yet, so a site with no media still makes a whole backup.
     *
     * @return list<string>
     */
    public function folders(): array
    {
        foreach (['originals' => 0700, 'public' => 0755] as $role => $mode) {
            if (! is_dir($this->roots[$role])) {
                mkdir($this->roots[$role], $mode, true);
            }
        }

        $folders = array_values(array_unique([$this->roots['originals'], $this->roots['public']]));
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
            if ($this->missingOn[$role] > 0) {
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
        return [
            'items' => $this->items,
            'files' => $this->files,
            'bytes' => $this->bytes,
            'variants' => ['files' => $this->variantFiles, 'bytes' => $this->variantBytes],
            'roots' => $this->roots,
            'snapshot_roots' => array_map(BackupSnapshots::pathInSnapshot(...), $this->roots),
            'originals' => $this->originals,
            'missing' => ['count' => $this->missingCount, 'files' => $this->missing],
        ];
    }

    /**
     * @throws BackupRefused
     */
    private static function root(string $name): string
    {
        $disk = Storage::disk($name);

        if (! $disk instanceof LocalFilesystemAdapter) {
            throw new BackupRefused("The media disk “{$name}” isn’t a folder on this server, so Teme can’t back its files up.");
        }

        return rtrim($disk->path(''), '/\\');
    }

    private function add(object $row): void
    {
        $this->items++;
        $path = (string) $row->original_path;
        $bytes = (int) $row->size_bytes;
        // An image's original is private; every other original is public (docs/architecture/media.md).
        $disk = (string) $row->kind === 'image' ? 'originals' : 'public';

        $this->originals[] = ['id' => (string) $row->id, 'disk' => $disk, 'path' => $path, 'bytes' => $bytes, 'sha256' => (string) $row->sha256];
        $this->note($disk, $path, $bytes);

        $variants = json_decode((string) $row->variants, true, flags: JSON_THROW_ON_ERROR);

        foreach (is_array($variants) ? $variants : [] as $variant) {
            if (is_array($variant) && is_string($variant['path'] ?? null)) {
                $size = (int) ($variant['bytes'] ?? 0);
                $this->variantFiles++;
                $this->variantBytes += $size;
                $this->note('public', $variant['path'], $size);
            }
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
