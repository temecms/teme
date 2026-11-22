<?php

/**
 * Answers an address the site itself didn't (I-297, I-858): an enabled
 * exact redirect from it, else the first enabled pattern that matches, kept
 * compiled in Cache::memo(), with where it sends readers made now. A
 * redirect whose item or file isn't there waits, and the address answers
 * "not found" (I-857). It keeps nothing itself between requests.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RedirectLookup
{
    /**
     * Every stored "not found" page PublicController draws carries it, so a
     * pattern redirect can remove them all.
     */
    public const string NOT_FOUND_TAG = 'not-found';

    /** Where Cache::memo() keeps the compiled patterns, forgotten after any redirect change commits. */
    public const string PATTERNS_KEY = 'teme.redirects.patterns';

    /** Where WordPress kept uploaded files unless a site moved them, below its address; a site's own folder is I-1884. */
    public const string UPLOADS = 'wp-content/uploads/';

    /**
     * What a redirect answers for this request path and query string, or
     * null when none does, or the one that matches waits for its item or file.
     */
    public function answer(string $rawPath, string $query): ?RedirectAnswer
    {
        $match = $this->match(PublicRequestPath::normalise($rawPath));

        if ($match === null) {
            return null;
        }

        [$rule, $captures] = $match;
        $location = $rule['target_kind'] === null ? null : $this->location($rule, $captures, $query);

        // Its item or file isn't there: the first match decides, and the address answers "not found" (I-857).
        if ($rule['target_kind'] !== null && $location === null) {
            return null;
        }

        return new RedirectAnswer($rule['id'], $rule['status'], $location);
    }

    /**
     * Returns each of these paths, in the source's form, that an enabled
     * redirect matches, waiting or not, with that redirect's ID: the 404
     * log's "Redirected" (I-871). One statement for the exact ones; the
     * patterns from the memo.
     *
     * @param  list<string>  $paths
     *
     * @return array<array-key, string>
     */
    public function matchedAll(array $paths): array
    {
        $readable = array_values(array_filter($paths, fn (string $path): bool => $path !== '' && RedirectSource::readable($path) && mb_strlen($path) <= RedirectSource::LONGEST));
        $matched = [];

        foreach (DB::table('redirects')->whereIn('source', $readable)->where('kind', 'exact')->where('enabled', true)->get(['id', 'source']) as $row) {
            $matched[(string) $row->source] = (string) $row->id;
        }

        foreach ($readable as $path) {
            if (isset($matched[$path])) {
                continue;
            }

            foreach ($this->patterns() as $pattern) {
                if (RedirectSource::captures($pattern['regex'], $pattern['names'], $path) !== null) {
                    $matched[$path] = $pattern['id'];

                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * Returns where an old WordPress file address sends readers: for a path
     * below wp-content/uploads/, in any case, of a file an import brought,
     * the item's public address now. A resized copy (-{w}x{h} before the
     * extension) goes to the item's nearest variant at least as wide, else
     * its widest; any other file of it to its widest variant, or for a file
     * that isn't an image its original. Null for any other path, a file no
     * longer here, or an image whose variants aren't made yet. One rule for
     * the whole folder, read from the import map's file rows, asked after
     * every redirect, so a person's own wins (docs/architecture/import.md).
     */
    public function oldFile(string $rawPath): ?string
    {
        $key = self::uploadsKey($rawPath);
        $media = $key === null ? null : self::oldFileMedia($key);

        return $media === null ? null : self::fileAddress($media, self::copyWidth($key));
    }

    /**
     * Returns the media item an old WordPress file address leads to, by the
     * import map's file rows of every source, oldest first: its ID, also
     * while its variants are still being made; null for any other path, or
     * a file no import brought or no longer here.
     *
     * @since next
     */
    public function oldFileItem(string $rawPath): ?string
    {
        $key = self::uploadsKey($rawPath);

        return $key === null ? null : self::oldFileMedia($key);
    }

    /**
     * The path below the uploads folder as the file map keys it, from the
     * raw path: never the normalised one, whose Unicode lower-casing would
     * miss a key. Null for a path outside the folder.
     */
    private static function uploadsKey(string $rawPath): ?string
    {
        $raw = ltrim($rawPath, '/');

        if (strlen($raw) <= strlen(self::UPLOADS) || strncasecmp($raw, self::UPLOADS, strlen(self::UPLOADS)) !== 0 || mb_strlen($raw) > RedirectSource::LONGEST) {
            return null;
        }

        $key = ConversionSite::fileKey(substr($raw, strlen(self::UPLOADS)));

        return RedirectSource::readable($key) ? $key : null;
    }

    /**
     * The media item behind a file key, from the first source that brought it.
     */
    private static function oldFileMedia(string $key): ?string
    {
        foreach (DB::table('import_sources')->orderBy('created_at')->orderBy('id')->pluck('id') as $source) {
            $media = (new ImportedMedia((string) $source))->file($key);

            if ($media !== null && Media::query()->whereKey($media)->exists()) {
                return $media;
            }
        }

        return null;
    }

    /**
     * The width a resized copy's name asks for, as WordPress names one:
     * -{w}x{h} before the extension; null for any other file.
     */
    private static function copyWidth(string $key): ?int
    {
        return preg_match('/-(\d{1,5})x\d{1,5}\.[a-z0-9]+$/', $key, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * The enabled redirect that matches this path, exact first, with its
     * placeholders' values, or null. A path no source can be, empty, not
     * UTF-8, holding a control character or too long, matches none and is
     * never sent to PostgreSQL, which would refuse it.
     *
     * @return array{0: array{id: string, status: int, target_kind: string|null, target: string|null}, 1: array<string, string>}|null
     */
    private function match(string $path): ?array
    {
        if ($path === '' || ! RedirectSource::readable($path) || mb_strlen($path) > RedirectSource::LONGEST) {
            return null;
        }

        $exact = DB::table('redirects')->where('source', $path)->where('kind', 'exact')->where('enabled', true)->first(['id', 'status', 'target_kind', 'target']);

        if ($exact !== null) {
            return [[
                'id' => (string) $exact->id,
                'status' => (int) $exact->status,
                'target_kind' => $exact->target_kind === null ? null : (string) $exact->target_kind,
                'target' => $exact->target === null ? null : (string) $exact->target,
            ], []];
        }

        foreach ($this->patterns() as $pattern) {
            $captures = RedirectSource::captures($pattern['regex'], $pattern['names'], $path);

            if ($captures !== null) {
                return [$pattern, $captures];
            }
        }

        return null;
    }

    /**
     * The enabled patterns, compiled, in the order they are tried: the
     * longest fixed text before the first placeholder first, then one
     * without [...rest] before one with, then the oldest (I-287).
     *
     * @return list<array{id: string, status: int, target_kind: string|null, target: string|null, regex: string, names: list<string>, literal: int, rest: bool}>
     */
    private function patterns(): array
    {
        /** @var list<array{id: string, status: int, target_kind: string|null, target: string|null, regex: string, names: list<string>, literal: int, rest: bool}> */
        return Cache::memo()->rememberForever(self::PATTERNS_KEY, function (): array {
            $patterns = [];

            foreach (DB::table('redirects')->where('kind', 'pattern')->where('enabled', true)->orderBy('created_at')->orderBy('id')->get(['id', 'source', 'pattern', 'status', 'target_kind', 'target']) as $row) {
                $source = (string) $row->source;
                preg_match_all(RedirectSource::PLACEHOLDER, $source, $holders);

                $patterns[] = [
                    'id' => (string) $row->id,
                    'status' => (int) $row->status,
                    'target_kind' => $row->target_kind === null ? null : (string) $row->target_kind,
                    'target' => $row->target === null ? null : (string) $row->target,
                    'regex' => (string) $row->pattern,
                    'names' => $holders[2],
                    'literal' => strcspn($source, '['),
                    'rest' => str_contains($source, '[...'),
                ];
            }

            // Stable since PHP 8, so the oldest stays first among equals.
            usort($patterns, fn (array $a, array $b): int => [$b['literal'], $a['rest']] <=> [$a['literal'], $b['rest']]);

            return $patterns;
        });
    }

    /**
     * Where the redirect sends readers now, its placeholders filled and the
     * request's query passed on when the target has none of its own; null
     * while its item or file isn't there (I-857).
     *
     * @param  array{target_kind: string|null, target: string|null}  $rule
     * @param  array<string, string>  $captures
     */
    private function location(array $rule, array $captures, string $query): ?string
    {
        $target = (string) $rule['target'];

        $address = match ($rule['target_kind']) {
            RedirectTarget::ADDRESS => rtrim(url('/'), '/').self::filled($target, $captures),
            RedirectTarget::OUTSIDE => self::filled($target, $captures),
            RedirectTarget::CONTENT => self::itemAddress($target),
            RedirectTarget::MEDIA => self::fileAddress($target),
            default => null,
        };

        if ($address === null || $query === '' || strpbrk($address, '?#') !== false) {
            return $address;
        }

        return $address.'?'.$query;
    }

    /**
     * The target with each placeholder's value, percent-encoded part by part.
     *
     * @param  array<string, string>  $captures
     */
    private static function filled(string $target, array $captures): string
    {
        return (string) preg_replace_callback(
            RedirectSource::PLACEHOLDER,
            fn (array $holder): string => PublicRequestPath::encode($captures[$holder[2]] ?? ''),
            $target,
        );
    }

    /**
     * The item's address now, the site's own for the page chosen as the home
     * page, while it is published, live and public, as PathResolver::live()
     * reads it and while its language is shown; otherwise null.
     */
    private static function itemAddress(string $id): ?string
    {
        $type = DB::table('contents')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')
            ->whereNotNull('published_revision_id')->where('published_at', '<=', now())->value('type');

        if ($type === null) {
            return null;
        }

        $settings = AddressSettings::current();

        if ($settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId === $id) {
            return rtrim(url('/'), '/').'/';
        }

        $path = Paths::current(RedirectTarget::CONTENT, $id);

        // An item in a hidden language is as gone to readers as one in the trash (D7).
        return $path === null || app(SiteLanguages::class)->isHiddenPath($path) ? null : rtrim(url('/'), '/').PublicRequestPath::encode(Addresses::toUrl($path, (string) $type));
    }

    /**
     * The media file's public address now, from the disk's (rule 9): the
     * widest variant of an image, whose original is private, or the original
     * of anything else, or for a resized copy's width the nearest variant
     * at least that wide; null while it is trashed or has no public file yet.
     */
    private static function fileAddress(string $id, ?int $width = null): ?string
    {
        $media = Media::query()->find($id);

        if ($media === null) {
            return null;
        }

        if (! $media->isImage()) {
            return MediaDisks::public()->url($media->original_path);
        }

        return $width === null ? MediaUrls::largest($media->variants) : MediaUrls::nearest($media->variants, $width);
    }
}
