<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\Tools\BackupTools;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Backups\Tools\ResticRepository;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * A real restic repository in a folder of its own under the system's
 * temporary folder, made for one test with the restic this computer is
 * configured with (I-2400); the test's afterEach deletes it. It turns
 * restic's cache off, so nothing is left in storage/.
 */
final class TemporaryRepository
{
    public const string KEY = 'teme-test-repository-key';

    private function __construct(
        public readonly string $folder,
        public readonly ResticRepository $repository,
        public readonly string $id,
    ) {}

    /**
     * Fails, never skips, when restic isn't there (I-2400): a backup test
     * that skipped would pass a gate that proved nothing.
     */
    public static function make(string $key = self::KEY): self
    {
        config(['teme.backups.cache_path' => '']);

        $folder = self::freshFolder();
        $repository = new ResticRepository($folder, $key);
        $made = app(Restic::class)->run($repository, ['init']);
        $id = $made->message('initialized')['id'] ?? null;

        if (! $made->succeeded() || ! is_string($id)) {
            throw new RuntimeException('restic couldn’t make a test repository: '.$made->detail().' Is restic '.BackupTools::RESTIC_VERSION.' where TEME_RESTIC_BINARY says, or on the PATH?');
        }

        return new self($folder, $repository, $id);
    }

    /**
     * A path under the system's temporary folder that nothing has made.
     */
    public static function freshFolder(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-restic-'.Str::lower((string) Str::ulid());
    }

    public function delete(): void
    {
        self::remove($this->folder);
    }

    /**
     * Deletes a folder this fixture made, by walking it: never Storage's
     * deleteDirectory(), which application code may not call either.
     */
    public static function remove(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            // restic leaves its files read-only on Linux.
            @chmod($item->getPathname(), 0700);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($folder);
    }
}
