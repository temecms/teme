<?php

/**
 * Two revisions of one article, compared: who made each, the fields and
 * terms that changed, and the blocks that stayed, arrived, left, changed or
 * moved. It is computed each time and never stored.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Cards\DiffBlock;
use App\Domain\Cards\DocumentDiff;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\MediaField;
use App\Domain\Fields\Types\SelectField;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RevisionComparison
{
    /**
     * The comparison, or null when the viewer may not edit the article. By
     * default it compares the published revision, or the working revision's
     * predecessor when nothing is published, with the working revision. A
     * missing or trashed article, or a revision of another article, is a 404.
     *
     * @return array{title: string, workingRevisionId: string|null, from: array{id: string, kind: string, author: string|null, createdAt: string}, to: array{id: string, kind: string, author: string|null, createdAt: string}, blocks: list<DiffBlock>, fields: list<array{key: string, label: string, before: string|null, after: string|null}>, terms: list<array{taxonomy: string, label: string, added: list<string>, removed: list<string>}>}|null
     */
    public static function between(string $contentId, User $viewer, ?string $from, ?string $to): ?array
    {
        $content = Content::query()->findOrFail($contentId);

        if (! Gate::forUser($viewer)->allows('update', $content)) {
            return null;
        }

        $toId = $to ?? (string) $content->working_revision_id;
        $fromId = $from ?? $content->published_revision_id ?? self::before($content->id, $toId) ?? $toId;

        $older = ContentRevision::query()->where('content_id', $content->id)->findOrFail($fromId);
        $newer = ContentRevision::query()->where('content_id', $content->id)->findOrFail($toId);

        return [
            'title' => (string) $content->title,
            'workingRevisionId' => $content->working_revision_id,
            'from' => self::summary($older),
            'to' => self::summary($newer),
            'blocks' => app(DocumentDiff::class)->between((array) $older->document, (array) $newer->document),
            'fields' => self::fieldChanges($content->type, (array) $older->fields, (array) $newer->fields),
            'terms' => self::termChanges($content->type, array_map('strval', (array) $older->terms), array_map('strval', (array) $newer->terms)),
        ];
    }

    /**
     * Each field whose stored value differs between the two revisions: the
     * type's fields in registry order, then any other key. A key no field has
     * any more — a removed plugin's — is shown by the key, never an error.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     *
     * @return list<array{key: string, label: string, before: string|null, after: string|null}>
     */
    private static function fieldChanges(string $type, array $before, array $after): array
    {
        $registry = app(FieldRegistry::class);
        $others = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        sort($others);
        $keys = array_values(array_unique([...array_map(fn (Field $field): string => $field->key, $registry->forType($type)), ...$others]));
        $changes = [];

        foreach ($keys as $key) {
            $field = $registry->find($key);

            if ($field?->type instanceof MediaField) {
                $what = self::mediaChange($before[$key] ?? null, $after[$key] ?? null);

                if ($what !== null) {
                    $changes[] = ['key' => $key, 'label' => $field->label, 'before' => null, 'after' => $what];
                }

                continue;
            }

            $old = self::describe($field, $before[$key] ?? null);
            $new = self::describe($field, $after[$key] ?? null);

            if (($before[$key] ?? null) === ($after[$key] ?? null) || $old === $new) {
                continue;
            }

            $changes[] = ['key' => $key, 'label' => $field->label ?? $key, 'before' => $old, 'after' => $new];
        }

        return $changes;
    }

    /**
     * What changed on a media field, in words (I-438): added, removed or
     * replaced; otherwise each part that changed — marked decorative or no
     * longer, and the alt text before and after — joined with "; ". Null
     * when nothing a reader would notice changed.
     */
    private static function mediaChange(mixed $before, mixed $after): ?string
    {
        $id = fn (mixed $value): ?string => is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '' ? $value['id'] : null;
        [$old, $new] = [$id($before), $id($after)];

        if ($old === null || $new === null) {
            return match (true) {
                $old === $new => null,
                $old === null => 'image added',
                default => 'image removed',
            };
        }

        if ($old !== $new) {
            return 'image replaced';
        }

        /** @var array<string, mixed> $before */
        /** @var array<string, mixed> $after */
        $parts = [];
        $wasDecorative = ($before['decorative'] ?? false) === true;
        $isDecorative = ($after['decorative'] ?? false) === true;

        if ($wasDecorative !== $isDecorative) {
            $parts[] = $isDecorative ? 'marked decorative' : 'no longer decorative';
        }

        $oldAlt = self::describe(null, $before['alt'] ?? null);
        $newAlt = self::describe(null, $after['alt'] ?? null);

        if ($oldAlt !== $newAlt) {
            $quoted = fn (?string $alt): string => $alt === null ? 'empty' : '“'.$alt.'”';
            $parts[] = 'alt text changed: '.$quoted($oldAlt).' → '.$quoted($newAlt);
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * One stored value as short text, or null when it is empty.
     */
    private static function describe(?Field $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        $text = is_array($value) ? (string) json_encode($value) : (string) $value;

        if ($field?->type instanceof SelectField) {
            $text = $field->options[$text] ?? $text;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : Str::limit($text, 80);
    }

    /**
     * The terms added and removed, per taxonomy: the type's taxonomies in
     * registry order, then any other. A term deleted since has no name to
     * show, and is left out.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     *
     * @return list<array{taxonomy: string, label: string, added: list<string>, removed: list<string>}>
     */
    private static function termChanges(string $type, array $before, array $after): array
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return [];
        }

        $rows = DB::table('terms')->whereIn('id', [...$added, ...$removed])->get(['id', 'name', 'taxonomy'])->keyBy('id');
        $taxonomies = app(TaxonomyRegistry::class);
        $changes = [];

        foreach (['added' => $added, 'removed' => $removed] as $side => $ids) {
            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;

                if ($row === null) {
                    continue;
                }

                $key = (string) $row->taxonomy;
                $changes[$key] ??= [
                    'taxonomy' => $key,
                    'label' => $taxonomies->has($key) ? $taxonomies->get($key)->plural : $key,
                    'added' => [],
                    'removed' => [],
                ];
                $changes[$key][$side][] = (string) $row->name;
            }
        }

        $order = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, $taxonomies->forType($type));
        $rank = fn (string $key): int => ($at = array_search($key, $order, true)) === false ? PHP_INT_MAX : $at;
        uksort($changes, fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        return array_values($changes);
    }

    /**
     * The revision made just before this one, or null when it is the first.
     */
    private static function before(string $contentId, string $revisionId): ?string
    {
        $revision = DB::table('content_revisions')->where('id', $revisionId)->first(['created_at']);

        if ($revision === null) {
            return null;
        }

        $id = DB::table('content_revisions')
            ->where('content_id', $contentId)
            ->where('id', '!=', $revisionId)
            ->whereRaw('(created_at, id) < (?::timestamptz, ?)', [$revision->created_at, $revisionId])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @return array{id: string, kind: string, author: string|null, createdAt: string}
     */
    private static function summary(ContentRevision $revision): array
    {
        $author = $revision->author_id === null ? null : DB::table('users')->where('id', $revision->author_id)->value('name');
        $created = $revision->getAttribute('created_at');

        return [
            'id' => (string) $revision->id,
            'kind' => (string) $revision->kind,
            'author' => $author === null ? null : (string) $author,
            'createdAt' => $created instanceof DateTimeInterface ? Carbon::instance($created)->toIso8601String() : Carbon::parse((string) $created)->toIso8601String(),
        ];
    }
}
