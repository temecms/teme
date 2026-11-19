<?php

/**
 * The backup benchmark's site made real (I-367): every media row an image
 * whose original and five sizes are files of random bytes in the media
 * folders, each original's SHA-256 on its row; the night's new images; and
 * reader passwords for a restore to re-encrypt. It writes only the
 * benchmark's own database and the two media folders it is given.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Benchmark\BackupBench;

use App\Domain\Contents\ReaderPasswords;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BackupBenchSite
{
    /** Rows read, and SHA-256s written, a statement at a time. */
    private const int PAGE = 1000;

    /** The password every protected item gets: a benchmark's, never a reader's. */
    private const string READER_PASSWORD = 'bench-reader-password';

    /**
     * The seeder's media ID for number $n.
     */
    public static function mediaId(int $n): string
    {
        return 'M'.str_pad((string) $n, 25, '0', STR_PAD_LEFT);
    }

    /**
     * Makes media rows from number $from onward images: an original of
     * 150,000 to 600,000 bytes and the seeder's five sizes (I-2404), each at
     * $scale of its size and at least 1,024 bytes, at paths of its own ID.
     */
    public function shape(int $from, float $scale): void
    {
        DB::statement(<<<'SQL'
            UPDATE media AS m SET
                kind = 'image', mime_type = 'image/jpeg', extension = 'jpg', animated = false,
                original_filename = m.name || '.jpg',
                original_path = m.id || '/v1/' || m.name || '.jpg',
                width = 1200 + (n.g % 3000), height = 800 + (n.g % 2000),
                size_bytes = greatest(1024, round((150000 + (n.g * 7919) % 450000) * ?::numeric))::bigint,
                variants = jsonb_build_object(
                    'w320', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w320.webp', 'width', 320, 'height', 213, 'bytes', greatest(1024, round(18000 * ?::numeric))::bigint),
                    'w640', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w640.webp', 'width', 640, 'height', 427, 'bytes', greatest(1024, round(52000 * ?::numeric))::bigint),
                    'w960', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w960.webp', 'width', 960, 'height', 640, 'bytes', greatest(1024, round(98000 * ?::numeric))::bigint),
                    'w1280', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w1280.webp', 'width', 1280, 'height', 853, 'bytes', greatest(1024, round(150000 * ?::numeric))::bigint),
                    'thumb', jsonb_build_object('path', m.id || '/v1/' || m.name || '-thumb.webp', 'width', 300, 'height', 300, 'bytes', greatest(1024, round(14000 * ?::numeric))::bigint)
                ),
                variant_set = 1, processing = 'ready'
            FROM (SELECT id, substr(id, 2)::bigint AS g FROM media WHERE id >= ?) AS n
            WHERE m.id = n.id
            SQL, [$scale, $scale, $scale, $scale, $scale, $scale, self::mediaId($from)]);
    }

    /**
     * Writes the files of media rows from number $from onward, by their
     * recorded paths, random bytes of each recorded size, and records each
     * original's SHA-256. Its files, their bytes and the originals' bytes.
     *
     * @param  array{originals: string, public: string}  $roots
     *
     * @return array{files: int, bytes: int, originalBytes: int}
     */
    public function write(int $from, array $roots): array
    {
        [$files, $bytes, $originalBytes, $after] = [0, 0, 0, null];

        do {
            $rows = DB::table('media')
                ->select(['id', 'original_path', 'size_bytes', 'variants'])
                ->where('id', '>=', self::mediaId($from))
                ->when($after !== null, fn (Builder $query): Builder => $query->where('id', '>', $after))
                ->orderBy('id')
                ->limit(self::PAGE)
                ->get();
            [$ids, $hashes] = [[], []];

            foreach ($rows as $row) {
                $after = (string) $row->id;
                // Random, so restic can't deduplicate or compress what a real photo wouldn't let it.
                $original = random_bytes(max(1, (int) $row->size_bytes));
                $this->put($roots['originals'], (string) $row->original_path, $original);
                $ids[] = $after;
                $hashes[] = hash('sha256', $original);
                $files++;
                $bytes += strlen($original);
                $originalBytes += strlen($original);
                $variants = json_decode((string) $row->variants, true, flags: JSON_THROW_ON_ERROR);

                foreach (is_array($variants) ? $variants : [] as $variant) {
                    if (is_array($variant) && is_string($variant['path'] ?? null)) {
                        $size = max(1, (int) ($variant['bytes'] ?? 0));
                        $this->put($roots['public'], $variant['path'], random_bytes($size));
                        $files++;
                        $bytes += $size;
                    }
                }
            }

            if ($ids !== []) {
                DB::statement(<<<'SQL'
                    UPDATE media AS m SET sha256 = v.sha256
                    FROM unnest(?::text[], ?::text[]) AS v(id, sha256)
                    WHERE m.id = v.id
                    SQL, ['{'.implode(',', $ids).'}', '{'.implode(',', $hashes).'}']);
            }
        } while ($rows->count() === self::PAGE);

        return ['files' => $files, 'bytes' => $bytes, 'originalBytes' => $originalBytes];
    }

    /**
     * The night before the second backup: $count new images after number
     * $after, copied from the first rows and made the benchmark's own, with
     * their files; and ten times as many items saved again. The new files,
     * their bytes and the originals' bytes.
     *
     * @param  array{originals: string, public: string}  $roots
     *
     * @return array{files: int, bytes: int, originalBytes: int}
     */
    public function night(int $after, int $count, float $scale, array $roots): array
    {
        DB::statement(<<<'SQL'
            INSERT INTO media (id, kind, mime_type, extension, original_filename, name, original_path, file_version,
                size_bytes, sha256, width, height, animated, title, alt, caption, credit, focal_x, focal_y,
                variants, variant_set, processing, uploaded_by, uploaded_for, created_at, updated_at, deleted_at)
            SELECT 'M' || lpad((?::bigint + s.n)::text, 25, '0'), s.kind, s.mime_type, s.extension, s.original_filename,
                'night-' || (?::bigint + s.n), s.original_path, s.file_version, s.size_bytes, s.sha256, s.width, s.height,
                s.animated, s.title, s.alt, s.caption, s.credit, s.focal_x, s.focal_y, s.variants, s.variant_set,
                s.processing, s.uploaded_by, NULL, now(), now(), NULL
            FROM (SELECT m.*, row_number() OVER (ORDER BY m.id) AS n FROM media m WHERE m.deleted_at IS NULL ORDER BY m.id LIMIT ?) AS s
            SQL, [$after, $after, $count]);
        DB::statement('UPDATE contents SET updated_at = now() WHERE id IN (SELECT id FROM contents ORDER BY id LIMIT ?)', [$count * 10]);
        $this->shape($after + 1, $scale);

        return $this->write($after + 1, $roots);
    }

    /**
     * Protects every $every-th item with a reader password, leaving the ten
     * newest published items open for a restore's smoke test, so a restore
     * has values to re-encrypt (I-2540). How many it protected.
     */
    public function secrets(int $every): int
    {
        return DB::update(<<<'SQL'
            UPDATE contents SET visibility = 'protected', reader_password = ?
            WHERE id IN (SELECT c.id FROM (SELECT id, row_number() OVER (ORDER BY id) AS n FROM contents) AS c WHERE c.n % ? = 0)
              AND id NOT IN (SELECT id FROM contents WHERE published_at IS NOT NULL AND published_at <= now() ORDER BY published_at DESC LIMIT 10)
            SQL, [ReaderPasswords::seal(self::READER_PASSWORD), $every]);
    }

    private function put(string $root, string $path, string $bytes): void
    {
        $file = $root.DIRECTORY_SEPARATOR.$path;
        $folder = dirname($file);

        if (! is_dir($folder) && ! mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new RuntimeException("Couldn’t make {$folder}.");
        }

        if (file_put_contents($file, $bytes) === false) {
            throw new RuntimeException("Couldn’t write {$file}.");
        }
    }
}
