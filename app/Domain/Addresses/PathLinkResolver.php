<?php

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\InternalLinks;
use App\Domain\Cards\LinkResolver;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Media\MediaLocations;
use Illuminate\Support\Facades\DB;

/**
 * Where a link by ID leads (rule 9): an article's current address from the
 * path table, site-relative with its trailing slash, a term's or a person's
 * page, or a library file's address; null when there is none, and the
 * renderer then leaves the text unlinked. Each target is looked up once per
 * render. The other way, a path on this site or a library file's address
 * leads back to what it names.
 */
final class PathLinkResolver implements InternalLinks, LinkResolver
{
    /** @var array<string, string|null> */
    private array $cache = [];

    /** @var array<string, string|null> a term's or a person's page, by "kind:id" */
    private array $archives = [];

    public function url(string $contentId): ?string
    {
        // On every call, found before or not: the link changes with the item (rule 9, I-263).
        app(RenderRecord::class)->tag(PageTags::content($contentId));

        if (! array_key_exists($contentId, $this->cache)) {
            // Joined as text, as every join of paths to contents is, with
            // the item named on both sides: the text alone would carry over
            // to contents as contents.id::text, which its key can't answer.
            $row = DB::table('paths')
                ->join('contents', 'paths.target_id', '=', DB::raw('contents.id::text'))
                ->where('paths.target_type', 'content')
                ->where('paths.target_id', $contentId)
                ->where('contents.id', $contentId)
                ->where('paths.is_current', true)
                ->first(['paths.path', 'contents.type']);

            // An item in a hidden language has no address readers may follow (D7).
            $this->cache[$contentId] = $row === null || app(SiteLanguages::class)->isHiddenPath((string) $row->path) ? null : Addresses::toUrl((string) $row->path, (string) $row->type);
        }

        return $this->cache[$contentId];
    }

    public function path(string $path): string
    {
        return $path;
    }

    public function target(string $kind, string $id): ?string
    {
        return match ($kind) {
            'content' => $this->url($id),
            'media' => $this->file($id),
            'term', 'user' => $this->archive($kind, $id),
            default => null,
        };
    }

    /**
     * A term's or a person's page now, from the path table, with the
     * archive pattern's trailing slash; null when there is none: deleted, or
     * a term of a taxonomy with no pages.
     */
    private function archive(string $kind, string $id): ?string
    {
        // On every call, found before or not: the link changes with the term or the person (I-263).
        app(RenderRecord::class)->tag($kind === 'term' ? PageTags::term($id) : PageTags::person($id));

        if (! array_key_exists("{$kind}:{$id}", $this->archives)) {
            $path = Paths::current($kind, $id);
            $this->archives["{$kind}:{$id}"] = $path === null || app(SiteLanguages::class)->isHiddenPath($path) ? null : Addresses::toUrl($path);
        }

        return $this->archives["{$kind}:{$id}"];
    }

    /**
     * A library file's public address now, as RedirectLookup draws a
     * redirect to it: an image at its widest size, its original being
     * private; any other file itself. Null while it is in the trash, gone,
     * or an image with no size made yet. CardMedia reads it with the
     * document's cards, in one statement, and records it on the page.
     */
    private function file(string $id): ?string
    {
        $item = app(CardMedia::class)->forIds([$id])[$id] ?? null;

        if ($item === null) {
            return null;
        }

        if ($item['fileUrl'] !== null) {
            return $item['fileUrl'];
        }

        $widest = null;

        foreach ($item['variants'] as $key => $variant) {
            if (preg_match('/^w\d+$/', $key) === 1 && ($widest === null || $variant['width'] > $widest['width'])) {
                $widest = $variant;
            }
        }

        return $widest === null ? null : $widest['url'];
    }

    public function targetAt(string $address): ?array
    {
        $file = $this->fileAt($address);

        if ($file !== null) {
            return ['kind' => 'media', 'id' => $file];
        }

        if (! str_starts_with($address, '/')) {
            return null;
        }

        $row = Paths::find(PublicRequestPath::normalise($address));

        // An old row under an archive page's address is not where a link there leads (I-3304).
        if ($row !== null && ! $row->is_current && TypeArchives::at($row->path) !== null) {
            return null;
        }

        // The kinds of Paths::KINDS a link can name; a type's archive page has none.
        return $row !== null && in_array($row->target_type, ['content', 'term', 'user'], true)
            ? ['kind' => $row->target_type, 'id' => $row->target_id]
            : null;
    }

    /**
     * The library item whose file this address names, or null: any media
     * location's public address, or its path where that location is served
     * from this site, so an address copied before or after a move still
     * leads to the item (I-3456); then the item's ID, a file version and a
     * name, as StorageKeys makes them, so any size of an image and any
     * version of a file lead to the item. Its row must still exist, in the
     * trash or not.
     */
    private function fileAt(string $address): ?string
    {
        $key = null;
        $site = self::bare((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        // The server's own folder too, where a pull CDN at MEDIA_URL stands in front of it.
        $server = rtrim((string) config('app.url'), '/').'/storage/media';

        foreach (array_unique([...MediaLocations::publicBases(), $server]) as $base) {
            $host = parse_url($base, PHP_URL_HOST);
            $path = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
            $here = ! is_string($host) || self::bare($host) === $site;

            $key = match (true) {
                stripos($address, "{$base}/") === 0 => substr($address, strlen($base) + 1),
                $here && str_starts_with($address, "{$path}/") => substr($address, strlen($path) + 1),
                default => null,
            };

            if ($key !== null) {
                break;
            }
        }

        if ($key === null || preg_match('~^([0-9A-HJKMNP-TV-Z]{26})/v[1-9][0-9]*/[^/]+$~i', $key, $found) !== 1) {
            return null;
        }

        $id = strtoupper($found[1]);

        return DB::table('media')->where('id', $id)->exists() ? $id : null;
    }

    /**
     * A host as this site compares it: lower-cased, without a leading `www.`.
     */
    private static function bare(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }
}
