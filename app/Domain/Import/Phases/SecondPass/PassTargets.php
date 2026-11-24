<?php

/**
 * What one source's import made of the old site, for the second pass: the
 * content phase's targets, and an old address that answers by a redirect
 * to an item or a file by ID, as an old attachment page does (I-244). Each
 * answer is a target still here.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\SecondPass;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Import\Convert\SourceTargets;
use App\Domain\Import\Phases\Content\MapTargets;
use Illuminate\Support\Facades\DB;

final readonly class PassTargets implements SourceTargets
{
    private MapTargets $map;

    public function __construct(string $sourceId)
    {
        $this->map = new MapTargets($sourceId);
    }

    /**
     * Returns what the old page at this key became, or null: the content
     * phase's answer, then an exact redirect of the import's to an item or
     * a file still here.
     */
    public function page(string $key): ?array
    {
        $found = $this->map->page($key);

        if ($found !== null) {
            return $found;
        }

        $row = DB::table('redirects')->where('source', PublicRequestPath::normalise($key))->where('kind', 'exact')->where('enabled', true)
            ->whereIn('target_kind', ['content', 'media'])->first(['target_kind', 'target']);

        if ($row === null) {
            return null;
        }

        $id = (string) $row->target;
        $table = $row->target_kind === 'content' ? 'contents' : 'media';

        return DB::table($table)->where('id', $id)->whereNull('deleted_at')->exists() ? ['kind' => (string) $row->target_kind, 'id' => $id] : null;
    }

    public function post(int $id): ?string
    {
        return $this->map->post($id);
    }

    public function term(string $taxonomy, int $id): ?string
    {
        return $this->map->term($taxonomy, $id);
    }

    public function termNamed(string $taxonomy, string $slug): ?string
    {
        return $this->map->termNamed($taxonomy, $slug);
    }

    public function user(int $id): ?string
    {
        return $this->map->user($id);
    }

    public function userNamed(string $nicename): ?string
    {
        return $this->map->userNamed($nicename);
    }

    public function outsideImage(string $address): ?string
    {
        return $this->map->outsideImage($address);
    }

    public function keepsOldSiteMedia(): bool
    {
        return false;
    }

    /**
     * Returns the WordPress IDs of this kind of file attached to this post.
     */
    public function attached(int $postId, string $type): array
    {
        return $this->map->attached($postId, $type);
    }
}
