<?php

/**
 * The extensions folder's releases: each a folder under releases/ named by a
 * lowercase ULID, and `current`, a relative link to the one that runs,
 * replaced in one rename. Only the installer writes here.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final readonly class ReleaseStore
{
    /** How many releases are kept: the current one and the two before it. */
    public const int KEEP = 3;

    public function __construct(private string $root) {}

    /** Returns the extensions folder. */
    public function root(): string
    {
        return $this->root;
    }

    /** Returns a release's folder; throws when the id isn't a lowercase ULID. */
    public function path(string $id): string
    {
        self::check($id);

        return "{$this->root}/releases/{$id}";
    }

    /** Makes a new, empty release folder and returns its path. */
    public function create(string $id): string
    {
        $path = $this->path($id);

        if (file_exists($path) || ! @mkdir($path, 0755, true)) {
            throw new InstallerFailed("Couldn't make the new release folder {$id}.");
        }

        return $path;
    }

    /** Returns the id `current` points at, or null when nothing is installed. */
    public function current(): ?string
    {
        // readlink(), never realpath(): the realpath cache would keep an old release.
        $target = @readlink("{$this->root}/current");

        return $target === false ? null : basename($target);
    }

    /**
     * Returns every release, oldest first.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $ids = [];

        foreach (glob("{$this->root}/releases/*", GLOB_ONLYDIR) ?: [] as $folder) {
            if (self::isId(basename($folder))) {
                $ids[] = basename($folder);
            }
        }

        sort($ids);

        return $ids;
    }

    /** Returns the newest release older than the current one, or null. */
    public function previous(): ?string
    {
        $current = $this->current();
        $older = array_values(array_filter($this->all(), fn (string $id): bool => $current !== null && strcmp($id, $current) < 0));

        return $older === [] ? null : $older[count($older) - 1];
    }

    /** Points `current` at this release in one rename, so no reader sees it missing. */
    public function swap(string $id): void
    {
        if (! is_dir($this->path($id))) {
            throw new InstallerFailed("Couldn't switch to the new release {$id}: it isn't there.");
        }

        $link = "{$this->root}/current";
        $next = "{$link}.".bin2hex(random_bytes(4));

        if (! @symlink("releases/{$id}", $next) || ! @rename($next, $link)) {
            @unlink($next);

            throw new InstallerFailed("Couldn't switch to the new release {$id}.");
        }
    }

    /** Deletes a release that never went live, or no longer runs; never the current one. */
    public function delete(string $id): void
    {
        if ($id === $this->current()) {
            throw new InstallerFailed("The release {$id} is the one that runs, so it isn't deleted.");
        }

        File::deleteDirectory($this->path($id));
    }

    /**
     * Deletes the releases older than the newest kept, never the current one
     * or the one before it. Returns the ids deleted.
     *
     * @return list<string>
     */
    public function prune(int $keep = self::KEEP): array
    {
        $current = $this->current();

        if ($current === null) {
            return [];
        }

        $live = array_values(array_filter($this->all(), fn (string $id): bool => strcmp($id, $current) <= 0));
        $kept = array_slice($live, -max(2, $keep));
        $deleted = [];

        foreach ($live as $id) {
            if (! in_array($id, $kept, true)) {
                $this->delete($id);
                $deleted[] = $id;
            }
        }

        return $deleted;
    }

    /** Whether this is a release id: a lowercase ULID. */
    public static function isId(string $id): bool
    {
        return preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $id) === 1;
    }

    private static function check(string $id): void
    {
        if (! self::isId($id)) {
            throw new InvalidArgumentException("\"{$id}\" isn't a release id.");
        }
    }
}
