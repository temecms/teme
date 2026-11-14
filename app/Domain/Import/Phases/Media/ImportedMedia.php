<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\MediaTargets;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The media one source's import brought in, as the converter asks for it
 * (I-338): by WordPress attachment ID, by any file address below the old
 * site's uploads folder, by the address of an image from a site the person
 * ticked, and by the WordPress post they were uploaded to. Every answer is
 * an item still here.
 */
final readonly class ImportedMedia implements MediaTargets
{
    /** An image file's extensions: an address ending in one names its file, and a query only resizes it. */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'svg', 'bmp', 'tif', 'tiff', 'ico'];

    public function __construct(private string $sourceId) {}

    /**
     * An outside image's key (I-1703): its host lower-cased without www.,
     * then its path as a file key, then its query, kept only when the path
     * doesn't name an image file (photo.php?id=1 and ?id=2 are two images);
     * never the fragment. Null for an address with no http or https host,
     * one with a user name, or a key longer than the map's.
     */
    public static function outsideKey(string $address): ?string
    {
        $address = trim($address);
        $parts = parse_url(str_starts_with($address, '//') ? 'https:'.$address : $address);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '' || isset($parts['user'])) {
            return null;
        }

        $path = ConversionSite::fileKey($parts['path'] ?? '/');
        $named = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
        $query = ($parts['query'] ?? '') === '' || $named ? '' : '?'.$parts['query'];
        $key = (string) preg_replace('/^www\./', '', strtolower($parts['host'])).$path.$query;

        return mb_check_encoding($key, 'UTF-8') && ! str_contains($key, "\0") && mb_strlen($key) <= 500 ? $key : null;
    }

    /**
     * Returns the media ID WordPress's attachment became, or null when it
     * wasn't imported or its item has been deleted here since.
     */
    public function attachment(int $id): ?string
    {
        return $this->first('attachment', [(string) $id]);
    }

    /**
     * Returns the media ID behind a file address below the uploads folder,
     * a key as ConversionSite::fileKey() makes it, for any size or edited
     * copy: the key, then without its size, then without -scaled or
     * -rotated; or null.
     */
    public function file(string $path): ?string
    {
        return mb_check_encoding($path, 'UTF-8') && ! str_contains($path, "\0") ? $this->first('file', WordPressFiles::reaches($path)) : null;
    }

    /**
     * Returns the media ID of an image from an outside site the person
     * ticked, brought in before posts converted, or null.
     */
    public function outside(string $src): ?string
    {
        $key = self::outsideKey($src);

        return $key === null ? null : $this->first('outside-image', [$key]);
    }

    /**
     * Returns the WordPress IDs of the attachments uploaded to this
     * WordPress post whose items are still here, in WordPress's ID order,
     * only one kind of media when $kind names it (image, audio, video,
     * document): what a [gallery] or [playlist] with no IDs shows.
     *
     * @return list<int>
     */
    public function attachedTo(int $parent, ?string $kind = null): array
    {
        return DB::table('import_map')
            ->join('media', 'media.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $this->sourceId)
            ->where('import_map.object_type', 'attachment')
            ->whereRaw("import_map.facts->'parent' = ?::jsonb", [(string) $parent])
            ->when($kind !== null, fn (Builder $query): Builder => $query->where('media.kind', $kind))
            ->orderByRaw('char_length(import_map.object_id), import_map.object_id')
            ->pluck('import_map.object_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The media ID of the first of these keys that a row of this type has,
     * its item still here, or null.
     *
     * @param  list<string>  $keys
     */
    private function first(string $type, array $keys): ?string
    {
        $found = DB::table('import_map')
            ->join('media', 'media.id', '=', 'import_map.teme_id')
            ->where('import_map.source_id', $this->sourceId)
            ->where('import_map.object_type', $type)
            ->whereIn('import_map.object_id', $keys)
            ->pluck('import_map.teme_id', 'import_map.object_id')
            ->all();

        foreach ($keys as $key) {
            if (isset($found[$key])) {
                return (string) $found[$key];
            }
        }

        return null;
    }
}
