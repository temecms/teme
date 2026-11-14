<?php

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use Illuminate\Support\Str;
use LogicException;

/**
 * A restore's private folder, storage/app/restore/{ulid} (mode 0700), for
 * the two files it writes: the dump, which pg_restore reads twice, and its
 * use-list. Removed by their known names, never by listing the folder
 * (tests/Architecture/NoStorageListingTest.php), when the restore ends or,
 * if PHP stops first, at shutdown.
 */
final class RestoreFiles
{
    public const string DUMP = 'teme.dump';

    public const string LIST = 'restore.list';

    private function __construct(public readonly string $folder) {}

    public static function make(): self
    {
        $parent = storage_path('app'.DIRECTORY_SEPARATOR.'restore');

        if (! is_dir($parent)) {
            mkdir($parent, 0700, true);
        }

        $files = new self($parent.DIRECTORY_SEPARATOR.Str::lower((string) Str::ulid()));
        mkdir($files->folder, 0700);
        register_shutdown_function($files->remove(...));

        return $files;
    }

    public function path(string $name): string
    {
        if (! in_array($name, [self::DUMP, self::LIST], true)) {
            throw new LogicException('Only the dump and the list are kept in a restore’s folder.');
        }

        return $this->folder.DIRECTORY_SEPARATOR.$name;
    }

    public function write(string $name, string $contents): void
    {
        file_put_contents($this->path($name), $contents);
        chmod($this->path($name), 0600);
    }

    public function remove(): void
    {
        foreach ([self::DUMP, self::LIST] as $name) {
            if (is_file($this->path($name))) {
                unlink($this->path($name));
            }
        }

        if (is_dir($this->folder)) {
            @rmdir($this->folder);
        }
    }
}
