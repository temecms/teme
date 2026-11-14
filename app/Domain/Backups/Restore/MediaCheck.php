<?php

declare(strict_types=1);

namespace App\Domain\Backups\Restore;

use App\Domain\Backups\Manifest;
use Illuminate\Support\Facades\DB;

/**
 * The restored media against the backup (I-2404): every original the
 * manifest names, at its path, with its SHA-256; every variant the restored
 * rows name, at its path. Read by recorded path, never by listing a folder.
 * What it finds is said, never a failure: the backup recorded what its
 * server lacked, and the site is better open with it named.
 */
final readonly class MediaCheck
{
    /** Files named in each list; the counts count them all. */
    public const int NAMED = 10;

    /**
     * @param  list<array{role: string, from: string, to: string}>  $pairs
     *
     * @return array{originals_missing: list<string>, originals_changed: list<string>, variants_missing: list<string>, counts: array{originals_missing: int, originals_changed: int, variants_missing: int}}
     */
    public function check(Manifest $manifest, array $pairs): array
    {
        $folders = [];

        foreach ($pairs as $pair) {
            $folders[$pair['role']] = $pair['to'];
        }

        // One pair: both disks share a folder.
        $folder = fn (string $role): string => $folders[$role] ?? $folders['public'];
        $found = ['originals_missing' => [], 'originals_changed' => [], 'variants_missing' => []];
        $counts = ['originals_missing' => 0, 'originals_changed' => 0, 'variants_missing' => 0];
        $note = function (string $kind, string $file) use (&$found, &$counts): void {
            $counts[$kind]++;

            if (count($found[$kind]) < self::NAMED) {
                $found[$kind][] = $file;
            }
        };

        foreach ((array) (((array) ($manifest->toArray()['media'] ?? []))['originals'] ?? []) as $original) {
            $role = (string) $original['disk'];
            $path = $folder($role).DIRECTORY_SEPARATOR.$original['path'];

            if (! is_file($path)) {
                $note('originals_missing', "{$role}:{$original['path']}");
            } elseif (! hash_equals((string) $original['sha256'], (string) hash_file('sha256', $path))) {
                $note('originals_changed', "{$role}:{$original['path']}");
            }
        }

        DB::table('media')->select(['id', 'variants'])->orderBy('id')->chunk(1000, function ($rows) use ($folder, $note): void {
            foreach ($rows as $row) {
                $variants = json_decode((string) $row->variants, true);

                foreach (is_array($variants) ? $variants : [] as $variant) {
                    if (is_array($variant) && is_string($variant['path'] ?? null) && ! is_file($folder('public').DIRECTORY_SEPARATOR.$variant['path'])) {
                        $note('variants_missing', "public:{$variant['path']}");
                    }
                }
            }
        });

        return [...$found, 'counts' => $counts];
    }
}
