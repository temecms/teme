<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Cards\References;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\MediaField;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Queries\ShareImageUse;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use Illuminate\Support\Facades\DB;

/**
 * Keeps media_usages equal to what an item's revisions say, as
 * TermProjection does for terms: the working revision's media while the item
 * is live, the published revision's while it is live and published. Moves
 * media.working_uses and published_uses by exactly the rows it changes.
 */
final class MediaProjection
{
    /**
     * Brings one item's rows into line with its revisions. Call in every
     * content Operation's perform(), right after TermProjection::sync(). An
     * image it newly records as a featured or social image, with no share
     * images yet, has them made on the queue after commit, as $actorId, the
     * person whose change used it, or as the operator (I-855, I-392).
     */
    public static function sync(Content $content, ?string $actorId = null): void
    {
        $desired = self::desired($content);
        $current = [];

        foreach (DB::table('media_usages')->where('content_id', $content->id)->get(['scope', 'ref', 'media_id']) as $row) {
            $current["{$row->scope}|{$row->ref}"] = (string) $row->media_id;
        }

        /** @var array<string, int> $deltas "scope|mediaId" => change */
        $deltas = [];
        $shared = [];

        foreach ($current as $key => $mediaId) {
            if (($desired[$key] ?? null) !== $mediaId) {
                [$scope, $ref] = explode('|', $key, 2);
                DB::table('media_usages')->where(['content_id' => $content->id, 'scope' => $scope, 'ref' => $ref])->delete();
                $deltas["{$scope}|{$mediaId}"] = ($deltas["{$scope}|{$mediaId}"] ?? 0) - 1;
            }
        }

        foreach ($desired as $key => $mediaId) {
            if (($current[$key] ?? null) === $mediaId) {
                continue;
            }

            [$scope, $ref] = explode('|', $key, 2);
            DB::table('media_usages')->insert(['content_id' => $content->id, 'media_id' => $mediaId, 'scope' => $scope, 'ref' => $ref]);
            $deltas["{$scope}|{$mediaId}"] = ($deltas["{$scope}|{$mediaId}"] ?? 0) + 1;

            if (in_array($ref, ShareImageUse::refs(), true)) {
                $shared[$mediaId] = true;
            }
        }

        // A fixed order, so two Operations cannot deadlock on the same items.
        ksort($deltas);

        foreach ($deltas as $key => $delta) {
            if ($delta === 0) {
                continue;
            }

            [$scope, $mediaId] = explode('|', $key, 2);
            DB::table('media')->where('id', $mediaId)->increment($scope === 'published' ? 'published_uses' : 'working_uses', $delta);
        }

        // An image just used as a featured or social image gets its share
        // images, as the person whose change used it (I-855).
        if ($shared !== [] && ($actorId !== null || Operator::isPresent())) {
            $lacking = DB::table('media')->whereIn('id', array_keys($shared))->where('kind', 'image')->whereNull('variants->social')->get(['id', 'file_version']);

            foreach ($lacking as $row) {
                GenerateMediaVariants::dispatch((string) $row->id, (int) $row->file_version, JobActor::of($actorId), (string) $content->id)->afterCommit();
            }
        }
    }

    /**
     * The media fields a content type has, as the ref each is recorded under.
     *
     * @return array<string, string> ref => field key
     */
    public static function refsFor(string $contentType): array
    {
        $refs = [];

        foreach (app(FieldRegistry::class)->forType($contentType) as $field) {
            if ($field->type instanceof MediaField) {
                $refs["field:{$field->key}"] = $field->key;
            }
        }

        return $refs;
    }

    /**
     * @return array<string, string> "scope|ref" => media ID
     */
    private static function desired(Content $content): array
    {
        // Any type's document can hold an image card, so only the trash ends
        // an item's uses; a type with no media fields still has its cards.
        if ($content->trashed()) {
            return [];
        }

        $refs = self::refsFor($content->type);
        $scopes = ['working' => $content->working_revision_id];

        if ($content->status === 'published' && $content->published_revision_id !== null) {
            $scopes['published'] = $content->published_revision_id;
        }

        $revisions = ContentRevision::query()->whereIn('id', array_values(array_filter($scopes)))
            ->get(['id', 'fields', 'document'])->keyBy('id');
        $wanted = [];

        foreach ($scopes as $scope => $revisionId) {
            $revision = $revisions[$revisionId] ?? null;
            $fields = (array) ($revision->fields ?? []);
            $document = (array) ($revision->document ?? []);

            foreach ($refs as $ref => $key) {
                $id = $fields[$key]['id'] ?? null;

                if (is_string($id) && $id !== '') {
                    $wanted["{$scope}|{$ref}"] = $id;
                }
            }

            foreach (References::mediaUses($document) as $ref => $id) {
                $wanted["{$scope}|{$ref}"] = $id;
            }
        }

        if ($wanted === []) {
            return [];
        }

        // Items deleted for good since the revision was written drop out here.
        $existing = DB::table('media')->whereIn('id', array_unique(array_values($wanted)))->pluck('id')->flip();

        return array_filter($wanted, fn (string $id): bool => $existing->has($id));
    }
}
