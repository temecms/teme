<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Media\MediaDisks;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Media rows committed by a second session, as another request's uploads
 * are: a backup's moment and pg_dump see only committed rows, never the
 * test's own transaction. Each file goes to the faked media disks; delete()
 * in afterEach removes the rows, and the next Storage::fake() the files.
 */
final class CommittedMedia
{
    private const string NAME = 'backup_committed_media';

    /** @var list<string> */
    private array $ids = [];

    /**
     * An image, its original on the originals disk and each variant on the
     * public disk, unless $withFiles is false. Its ID.
     *
     * @param  list<string>  $variants
     */
    public function image(array $variants = ['w640', 'thumb'], bool $withFiles = true): string
    {
        // Upper case, as media IDs are.
        $id = (string) Str::ulid();
        $original = "{$id}/1/original.jpg";
        $bytes = "original of {$id}";
        $map = [];

        foreach ($variants as $name) {
            $map[$name] = ['path' => "{$id}/1/{$name}.webp", 'width' => 640, 'height' => 427, 'bytes' => 9];
        }

        $this->insert($id, 'image', 'image/jpeg', 'jpg', $original, $bytes, $map);

        if ($withFiles) {
            MediaDisks::originals()->put($original, $bytes);

            foreach ($map as $variant) {
                MediaDisks::public()->put($variant['path'], 'webp file');
            }
        }

        return $id;
    }

    /**
     * A PDF, on the public disk. Its ID.
     */
    public function document(): string
    {
        $id = (string) Str::ulid();
        $path = "{$id}/1/guide.pdf";
        $bytes = "%PDF-1.7 {$id}";

        $this->insert($id, 'document', 'application/pdf', 'pdf', $path, $bytes, []);
        MediaDisks::public()->put($path, $bytes);

        return $id;
    }

    public function delete(): void
    {
        if ($this->ids !== []) {
            self::session()->table('media')->whereIn('id', $this->ids)->delete();
        }

        DB::purge(self::NAME);
    }

    /**
     * @param  array<string, array{path: string, width: int, height: int, bytes: int}>  $variants
     */
    private function insert(string $id, string $kind, string $mime, string $extension, string $path, string $bytes, array $variants): void
    {
        self::session()->table('media')->insert([
            'id' => $id,
            'kind' => $kind,
            'mime_type' => $mime,
            'extension' => $extension,
            'original_filename' => basename($path),
            'name' => pathinfo($path, PATHINFO_FILENAME),
            'original_path' => $path,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'title' => "Committed {$kind}",
            'variants' => json_encode($variants === [] ? new stdClass : $variants, JSON_THROW_ON_ERROR),
            'processing' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ids[] = $id;
    }

    private static function session(): ConnectionInterface
    {
        if (! array_key_exists(self::NAME, DB::getConnections())) {
            /** @var array<string, mixed> $config */
            $config = DB::connection()->getConfig();

            DB::connectUsing(self::NAME, [...$config, 'name' => self::NAME]);
        }

        return DB::connection(self::NAME);
    }
}
