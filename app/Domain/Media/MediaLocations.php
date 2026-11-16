<?php

declare(strict_types=1);

namespace App\Domain\Media;

use InvalidArgumentException;

/**
 * The places media can live (config/media.php, I-3456): the server's two
 * folders and the two buckets, each a public disk and an originals disk,
 * and which of them the site uses now.
 */
final class MediaLocations
{
    /**
     * Every location's name, as config/media.php lists them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(strval(...), array_keys(self::all()));
    }

    /**
     * Whether a location of that name exists.
     */
    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::all());
    }

    /**
     * The location's two disks, by role.
     *
     * @return array{public: string, originals: string}
     */
    public static function disks(string $name): array
    {
        $disks = self::all()[$name] ?? throw new InvalidArgumentException("There is no media location “{$name}”.");

        return ['public' => (string) $disks['public'], 'originals' => (string) $disks['originals']];
    }

    /**
     * The location whose two disks are both the ones the site uses, or null
     * for a site that keeps its roles in different locations.
     */
    public static function current(): ?string
    {
        foreach (self::all() as $name => $disks) {
            if ($disks['public'] === (string) config('media.disk') && $disks['originals'] === (string) config('media.originals_disk')) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * The public address each location's media are served at, without a
     * trailing slash: the site's public disk as it answers now, and every
     * other location's public disk that has an address of its own.
     *
     * @return list<string>
     */
    public static function publicBases(): array
    {
        $bases = [rtrim(MediaDisks::public()->url(''), '/')];

        foreach (self::all() as $disks) {
            $url = config("filesystems.disks.{$disks['public']}.url");

            if ($disks['public'] !== (string) config('media.disk') && is_string($url) && $url !== '') {
                $bases[] = rtrim($url, '/');
            }
        }

        return array_values(array_unique($bases));
    }

    /**
     * @return array<string, array{public: string, originals: string}>
     */
    private static function all(): array
    {
        /** @var array<string, array{public: string, originals: string}> $locations */
        $locations = (array) config('media.locations', []);

        return $locations;
    }
}
