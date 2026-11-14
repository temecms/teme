<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Redirects;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use Illuminate\Support\Facades\DB;

/**
 * Where an old site's rule sends readers, as ImportRedirect takes it (rule
 * 9): another site's address as written; one on the old site's own host as
 * its path, so moving the domain changes nothing; and that path as an item
 * or a file by ID wherever the import brought one there.
 */
final readonly class RuleTargets
{
    /** Where WordPress keeps uploaded files unless a site moved them, below its address; a site's own folder is I-1884. */
    private const string UPLOADS = 'wp-content/uploads/';

    public function __construct(private string $sourceId, private string $host) {}

    /**
     * @return array{to: string, contentId: string|null, mediaId: string|null}
     */
    public function resolve(string $to): array
    {
        $to = trim($to);

        if (preg_match('#^(?:https?:)?//#i', $to) === 1) {
            $absolute = str_starts_with($to, '//') ? 'https:'.$to : $to;
            $own = OldSiteUrls::own($absolute, $this->host);

            if ($own === null) {
                return self::address($absolute);
            }

            $fragment = (string) parse_url($absolute, PHP_URL_FRAGMENT);
            $to = $own['path'].($own['query'] === '' ? '' : '?'.$own['query']).($fragment === '' ? '' : '#'.$fragment);
        } elseif ($to !== '' && ! str_starts_with($to, '/')) {
            $to = '/'.$to;
        }

        // A placeholder is filled at each request: nothing to look up now.
        if ($to === '' || str_contains($to, '[')) {
            return self::address($to);
        }

        $path = (string) strtok($to, '?#');
        $query = (string) parse_url('https://teme.invalid'.$to, PHP_URL_QUERY);
        $home = trim($path, '/') === '';
        parse_str($query, $asked);

        foreach (['p', 'page_id'] as $name) {
            $id = $asked[$name] ?? null;

            if ($home && is_string($id) && ctype_digit($id)) {
                $item = app(ImportMap::class)->temeId($this->sourceId, SourceRef::of('post', $id));

                if ($item !== null && DB::table('contents')->where('id', $item)->exists()) {
                    return self::item($item);
                }
            }
        }

        $attachment = $asked['attachment_id'] ?? null;

        if ($home && is_string($attachment) && ctype_digit($attachment)) {
            $media = (new ImportedMedia($this->sourceId))->attachment((int) $attachment);

            if ($media !== null) {
                return self::file($media);
            }
        }

        if ($query !== '' || str_contains($to, '#')) {
            return self::address($to);
        }

        $relative = ltrim($path, '/');

        if (strncasecmp($relative, self::UPLOADS, strlen(self::UPLOADS)) === 0) {
            $media = (new ImportedMedia($this->sourceId))->file(ConversionSite::fileKey(substr($relative, strlen(self::UPLOADS))));

            if ($media !== null) {
                return self::file($media);
            }
        }

        $row = Paths::find(PublicRequestPath::normalise($path));

        if ($row !== null && $row->target_type === 'content') {
            return self::item((string) $row->target_id);
        }

        // A category's or an author's old address: where it is now, so readers take one step.
        if ($row !== null && ! $row->is_current) {
            $current = Paths::current((string) $row->target_type, (string) $row->target_id);

            if ($current !== null) {
                return self::address(Addresses::toUrl($current));
            }
        }

        return self::address($to);
    }

    /**
     * @return array{to: string, contentId: string|null, mediaId: string|null}
     */
    private static function address(string $to): array
    {
        return ['to' => $to, 'contentId' => null, 'mediaId' => null];
    }

    /**
     * @return array{to: string, contentId: string|null, mediaId: string|null}
     */
    private static function item(string $id): array
    {
        return ['to' => '', 'contentId' => $id, 'mediaId' => null];
    }

    /**
     * @return array{to: string, contentId: string|null, mediaId: string|null}
     */
    private static function file(string $id): array
    {
        return ['to' => '', 'contentId' => null, 'mediaId' => $id];
    }
}
