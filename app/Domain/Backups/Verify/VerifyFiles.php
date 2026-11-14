<?php

declare(strict_types=1);

namespace App\Domain\Backups\Verify;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The test restore's private work folder, storage/app/verify/{ulid}, mode 0700: the dump and the media files read back, each removed by its known name, then the folder; never a listing (NoStorageListingTest).
 */
final class VerifyFiles
{
    /** @var array<string, true> */
    private array $names = [];

    private function __construct(public readonly string $folder) {}

    public static function make(): self
    {
        $folder = storage_path('app/verify/'.Str::lower((string) Str::ulid()));

        if (! is_dir($folder)) {
            @mkdir($folder, 0700, true);
        }

        @chmod($folder, 0700);

        if (! is_dir($folder)) {
            throw new RuntimeException("Teme couldn’t make the folder {$folder}.");
        }

        return new self($folder);
    }

    /**
     * The full path of a work file, remembered so remove() can delete it.
     */
    public function path(string $name): string
    {
        if (preg_match('/^[a-z0-9.-]+$/', $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidArgumentException("A work file’s name is letters, digits, dots and hyphens: [{$name}].");
        }

        $this->names[$name] = true;

        return $this->folder.DIRECTORY_SEPARATOR.$name;
    }

    public function forget(string $name): void
    {
        $path = $this->path($name);

        if (is_file($path)) {
            unlink($path);
        }

        unset($this->names[$name]);
    }

    public function remove(): void
    {
        foreach (array_keys($this->names) as $name) {
            $path = $this->folder.DIRECTORY_SEPARATOR.$name;

            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->names = [];

        if (is_dir($this->folder)) {
            rmdir($this->folder);
        }
    }
}
