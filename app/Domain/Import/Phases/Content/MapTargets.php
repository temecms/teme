<?php

/**
 * What one source's import made of the old site, as the converter asks for
 * it (I-1702): its pages through the path table, where an imported item's
 * current address and WordPress's own and old ones are; its posts, terms
 * and people through the map; its media through the media import's map.
 * Each answer is one indexed query or two, and a target still here, so a
 * page job's memory never holds the whole map.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Contents\Models\Content;
use App\Domain\Import\Convert\SourceTargets;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\Terms\ImportedTerms;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class MapTargets implements SourceTargets
{
    /** The path table's targets an old page can lead to: the kinds of Paths::KINDS but a type's archive page. */
    private const array KINDS = ['content', 'term', 'user'];

    public function __construct(private string $sourceId) {}

    /**
     * Returns what the old page at this key became: an item out of the
     * trash, a term or a person, by the address the path table holds now or
     * held before; null otherwise, for the second pass.
     */
    public function page(string $key): ?array
    {
        $row = DB::table('paths')->where('path', PublicRequestPath::normalise($key))->whereIn('target_type', self::KINDS)->first(['target_type', 'target_id']);

        if ($row === null) {
            return null;
        }

        $kind = (string) $row->target_type;
        $id = (string) $row->target_id;
        $here = match ($kind) {
            'content' => DB::table('contents')->where('id', $id)->whereNull('deleted_at')->exists(),
            'term' => DB::table('terms')->where('id', $id)->exists(),
            default => DB::table('users')->where('id', $id)->exists(),
        };

        return $here ? ['kind' => $kind, 'id' => $id] : null;
    }

    /**
     * Returns the item the WordPress post or page with this ID became, out
     * of the trash, or null.
     */
    public function post(int $id): ?string
    {
        $temeId = (new ImportMap)->temeId($this->sourceId, SourceRef::of('post', $id));

        return $temeId !== null && Content::query()->whereKey($temeId)->exists() ? $temeId : null;
    }

    /**
     * Returns the term the WordPress term with this term ID in this WordPress
     * taxonomy became, still here, or null.
     */
    public function term(string $taxonomy, int $id): ?string
    {
        $termId = (new ImportedTerms)->byWordPressTerm($this->sourceId, $taxonomy, $id);

        return $termId !== null && DB::table('terms')->where('id', $termId)->exists() ? $termId : null;
    }

    /**
     * Returns the term this source made with this slug in the Teme taxonomy
     * WordPress's $taxonomy feeds, or null.
     */
    public function termNamed(string $taxonomy, string $slug): ?string
    {
        $key = null;

        foreach (app(TaxonomyRegistry::class)->all() as $candidate) {
            if ($candidate->wordpress === $taxonomy) {
                $key = $candidate->key;
            }
        }

        if ($key === null) {
            return null;
        }

        $id = DB::table('terms')
            ->join('import_map', fn (JoinClause $join): JoinClause => $join->on('import_map.teme_id', '=', 'terms.id')->where('import_map.teme_type', '=', 'term'))
            ->where('import_map.source_id', $this->sourceId)
            ->where('import_map.object_type', 'term')
            ->where('terms.taxonomy', $key)
            ->where('terms.slug', $slug)
            ->value('terms.id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Returns the person the WordPress user with this ID became, still
     * here, or null.
     */
    public function user(int $id): ?string
    {
        $userId = (new ImportMap)->temeId($this->sourceId, SourceRef::of('user', $id));

        return $userId !== null && User::query()->whereKey($userId)->exists() ? $userId : null;
    }

    /**
     * Returns the person the WordPress user with this nicename became, still
     * here, or null.
     */
    public function userNamed(string $nicename): ?string
    {
        $userId = ImportMapEntry::query()
            ->where('source_id', $this->sourceId)
            ->where('object_type', 'user')
            ->whereNotNull('teme_id')
            ->whereRaw('facts @> ?::jsonb', [json_encode(['nicename' => $nicename], JSON_THROW_ON_ERROR)])
            ->whereExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('users')->whereRaw('users.id::text = import_map.teme_id'))
            ->value('teme_id');

        return $userId === null ? null : (string) $userId;
    }

    /**
     * Returns the media ID of an image from a site the person ticked, brought
     * in before posts converted, or null (the media import's map, I-1703).
     */
    public function outsideImage(string $address): ?string
    {
        return (new ImportedMedia($this->sourceId))->outside($address);
    }

    /**
     * Returns the WordPress IDs of this kind of file attached to this post,
     * in WordPress's ID order (the media import's map, I-338).
     */
    public function attached(int $postId, string $type): array
    {
        return (new ImportedMedia($this->sourceId))->attachedTo($postId, $type);
    }
}
