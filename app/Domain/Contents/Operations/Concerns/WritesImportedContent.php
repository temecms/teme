<?php

/**
 * Writes a WordPress post or page as the import decided it (I-332), for
 * ImportContent and UpdateImportedContent: the item's row, one revision of
 * the import's, its schedule, review or trash, the projections every content
 * Operation keeps, and WordPress's own and old addresses as history rows that
 * answer with a 301. Used inside an Operation's perform() only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Concerns;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\Paths;
use App\Domain\Cards\BlockIds;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\PlainText;
use App\Domain\Contents\AuthorSort;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\ImportedItem;
use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\SearchText;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldValidator;
use App\Domain\Media\MediaProjection;
use App\Domain\Taxonomy\TermProjection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

trait WritesImportedContent
{
    /**
     * Writes the item as decided: a revision of the import's when its words,
     * fields, terms or title differ from its working revision's, the same
     * words with new block IDs counting as unchanged; its row's
     * details; the schedule or review its status needs; the projections; its
     * address; its old addresses. Returns whether it changed anything, the
     * move it kept answering, how many old addresses another target holds,
     * whether there were more than it keeps, WordPress's own address when it
     * is another target's current one, and the links the normaliser changed.
     *
     * @return array{wrote: bool, moved: array{from: string, to: string}|null, taken: int, many: bool, clash: array{from: string, holder: string}|null, links: list<array{from: string, to: string|null}>}
     */
    private function writeImported(Content $content, ImportedItem $item, ?string $actorId, ?ContentState $before): array
    {
        $links = [];
        $document = DocumentNormaliser::normalise($item->document, $links);
        $fields = app(FieldValidator::class)->validate($item->type, $item->fields);
        $terms = array_values(array_unique($item->terms));
        $current = $content->working_revision_id === null ? null : ContentRevision::query()->find($content->working_revision_id);

        // The same words with new block IDs are the stored document, its IDs kept (I-3362).
        if ($current !== null && BlockIds::without((array) $current->document) == BlockIds::without($document)) {
            $document = (array) $current->document;
        }

        // Loose comparison: JSONB gives a document's keys back in its own order.
        $revised = $current !== null && ($current->title !== $item->title || $current->document != $document || $current->fields != $fields || $current->terms !== $terms);

        $revision = $current === null || $revised ? ContentRevision::query()->create([
            'content_id' => $content->id,
            'document' => $document,
            'plain_text' => PlainText::of($document),
            'word_count' => PlainText::words($document),
            'fields' => $fields,
            'terms' => $terms,
            'title' => $item->title,
            'author_id' => (int) $item->authorId,
            'origin' => 'import',
            'kind' => 'import',
            'created_at' => $item->modifiedAt,
        ]) : $current;

        $published = $item->status === 'published';

        // Sealed again only when it changed: a new seal is a new string, and a rerun writes nothing (I-1700).
        $sealed = $item->visibility !== Visibility::Protected ? null : (ReaderPasswords::open($content) === $item->password ? $content->getAttribute('reader_password') : ReaderPasswords::seal((string) $item->password));

        // Its dates are WordPress's, never the moment of the import.
        $content->timestamps = false;
        $content->forceFill([
            'slug' => $item->slug,
            'status' => $item->status,
            'title' => $item->title,
            'author_id' => (int) $item->authorId,
            'author_sort' => AuthorSort::of(User::query()->whereKey($item->authorId)->value('name')),
            'comment_status' => $item->discussion,
            // The array cast compares with ===, and JSONB reorders keys: the same fields are kept as they were read.
            'fields' => $content->fields == $fields ? $content->fields : $fields,
            'published_at' => $item->publishedAt,
            'scheduled_for' => $item->status === 'scheduled' ? $item->publishedAt : null,
            'deleted_at' => $item->trashedAt,
            'guid' => $item->guid,
            'visibility' => $item->visibility,
            'reader_password' => $sealed,
            'working_revision_id' => $revision->id,
            // Only publishing moves the published pointer; a draft keeps the one it had, as UnpublishContent leaves it.
            'published_revision_id' => $published ? $revision->id : $content->published_revision_id,
            // With timestamps off the model compares this one as text: left alone when it is the same moment.
            'updated_at' => $content->updated_at !== null && CarbonImmutable::parse((string) $content->updated_at, 'UTC')->equalTo($item->modifiedAt) ? $content->updated_at : $item->modifiedAt,
        ]);
        $wrote = $current === null || $revised || $content->isDirty();
        $content->save();
        $content->timestamps = true;

        $wrote = $this->importedSchedule($content, $item, (string) $revision->id) || $wrote;
        $wrote = $this->importedReview($content, $item, (string) $revision->id, $actorId) || $wrote;

        // Every projection reads only the revision and the row: unchanged, they have nothing to do.
        if ($wrote) {
            ContentCounts::apply($before, ContentState::of($content));
            TermProjection::sync($content);
            MediaProjection::sync($content, $actorId);
            LinkProjection::sync($content);
            SearchText::sync($content);
            app(PathProjection::class)->syncContent($content);
        }

        // Always: WordPress may have given it an old address since, with nothing else changed.
        [$moved, $taken, $many, $clash] = $this->importedOldAddresses($content, $item, $terms, $fields);

        return ['wrote' => $wrote, 'moved' => $moved, 'taken' => $taken, 'many' => $many, 'clash' => $clash, 'links' => $links];
    }

    /**
     * A scheduled item's one pending schedule, made again when its revision
     * or time changed; any other item's pending schedule cancelled. Whether
     * it wrote one.
     */
    private function importedSchedule(Content $content, ImportedItem $item, string $revisionId): bool
    {
        if ($item->status !== 'scheduled') {
            ScheduledPublication::cancelPendingFor((string) $content->id, match (true) {
                $item->trashedAt !== null => 'trashed',
                $item->status === 'published' => 'published',
                default => 'cancelled',
            });

            return false;
        }

        $pending = ScheduledPublication::query()->where('content_id', $content->id)
            ->whereNull('completed_at')->whereNull('failed_at')->whereNull('cancelled_at')->first();

        if ($pending !== null && $pending->revision_id === $revisionId && $item->publishedAt !== null && $pending->run_at->equalTo($item->publishedAt)) {
            return false;
        }

        ScheduledPublication::cancelPendingFor((string) $content->id, 'replaced');
        ScheduledPublication::query()->create([
            'content_id' => $content->id,
            'revision_id' => $revisionId,
            'action' => 'publish',
            'run_at' => $item->publishedAt,
            'created_by' => $item->schedulerId === null ? null : (int) $item->schedulerId,
        ]);

        return true;
    }

    /**
     * An item in review's one open review, of the revision the import writes
     * now, submitted by its author when WordPress last changed it: an open
     * review of other words is withdrawn and another opened, as approving it
     * would publish those words. Any other item's closed. Whether it wrote one.
     */
    private function importedReview(Content $content, ImportedItem $item, string $revisionId, ?string $actorId): bool
    {
        if ($item->status !== 'review') {
            ContentReview::closePendingFor((string) $content->id, $item->status === 'published' ? 'published' : 'withdrawn', $actorId);

            return false;
        }

        $open = ContentReview::query()->where('content_id', $content->id)->whereNull('decided_at')->first();

        if ($open !== null && $open->revision_id === $revisionId) {
            return false;
        }

        ContentReview::closePendingFor((string) $content->id, 'withdrawn', $actorId);
        ContentReview::query()->create([
            'content_id' => $content->id,
            'revision_id' => $revisionId,
            'submitted_by' => (int) $item->authorId,
            'submitted_at' => $item->modifiedAt,
        ]);

        return true;
    }

    /**
     * WordPress's own address and its old ones, as history rows that answer
     * with a 301 (I-061): for a published item out of the trash, never the
     * home address, one the site keeps for itself, or one another target
     * holds. The move it kept answering, how many were another's, whether
     * there were more than it keeps, and WordPress's own address when it is
     * another target's current one, with that target in words.
     *
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $fields
     *
     * @return array{0: array{from: string, to: string}|null, 1: int, 2: bool, 3: array{from: string, holder: string}|null}
     */
    private function importedOldAddresses(Content $content, ImportedItem $item, array $terms, array $fields): array
    {
        $current = Paths::current('content', (string) $content->id);

        if ($current === null || $content->trashed() || $content->status !== 'published') {
            return [null, 0, false, null];
        }

        $candidates = [];
        $many = false;

        foreach ($this->importedOldPaths($content, $item, $terms, $fields) as $path) {
            if ($path === '' || $path === $current || in_array($path, $candidates, true)) {
                continue;
            }

            if (count($candidates) === ImportedContents::MOST_OLD_ADDRESSES) {
                $many = true;

                break;
            }

            $candidates[] = $path;
        }

        $held = [];

        foreach (DB::table('paths')->whereIn('path', $candidates)->get(['path', 'target_type', 'target_id', 'is_current']) as $row) {
            $held[(string) $row->path] = $row;
        }

        $wordpress = $item->wordpressPath;
        $free = [];
        $taken = 0;
        $clash = null;

        foreach ($candidates as $path) {
            $row = $held[$path] ?? null;

            if ($row !== null && $row->target_type === 'content' && (string) $row->target_id === (string) $content->id) {
                continue;
            }

            // WordPress's own address answering another target: a wrong page the check would pass.
            if ($row !== null && $path === $wordpress && (bool) $row->is_current) {
                $clash = ['from' => $path, 'holder' => Paths::describe((string) $row->target_type, (string) $row->target_id)];

                continue;
            }

            if ($row !== null || Paths::taken(explode('/', $path)[0])) {
                $taken++;

                continue;
            }

            $free[] = $path;
        }

        $now = now();
        $written = $free === [] ? 0 : DB::table('paths')->insertOrIgnore(array_map(fn (string $path): array => [
            'path' => $path, 'target_type' => 'content', 'target_id' => (string) $content->id, 'is_current' => false, 'created_at' => $now, 'replaced_at' => $now,
        ], $free));

        // Taken between the read and the write, by another item's transaction.
        $taken += count($free) - $written;

        $answers = $wordpress !== null && $wordpress !== '' && $wordpress !== $current && $clash === null
            && DB::table('paths')->where('path', $wordpress)->where('target_type', 'content')->where('target_id', (string) $content->id)->exists();

        return [$answers ? ['from' => $wordpress, 'to' => $current] : null, $taken, $many, $clash];
    }

    /**
     * WordPress's own address, then the addresses Teme's pattern gives its
     * old slugs and old dates as WordPress's old-slug redirect finds them
     * (I-061): each old slug with the current date, the current slug with
     * each old date, each old slug with each old date.
     *
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $fields
     *
     * @return iterable<int, string>
     */
    private function importedOldPaths(Content $content, ImportedItem $item, array $terms, array $fields): iterable
    {
        if ($item->wordpressPath !== null) {
            yield $item->wordpressPath;
        }

        $timezone = app(SettingsReader::class)->string('general', 'timezone');
        // An old date is a local day; noon keeps it that day in the site's timezone.
        $dates = array_map(fn (string $date): CarbonImmutable => CarbonImmutable::parse($date.' 12:00:00', $timezone), $item->oldDates);
        $author = Addresses::ownerSlug($content, $content->author_id === null ? null : (int) $content->author_id);
        $primary = $fields[BuiltInFields::PRIMARY_CATEGORY] ?? null;
        $pairs = [];

        foreach ($item->oldSlugs as $slug) {
            $pairs[] = [$slug, $content->published_at];
        }

        foreach ($dates as $date) {
            $pairs[] = [$item->slug, $date];
        }

        foreach ($item->oldSlugs as $slug) {
            foreach ($dates as $date) {
                $pairs[] = [$slug, $date];
            }
        }

        foreach ($pairs as [$slug, $date]) {
            $address = Addresses::wouldHave($content, $slug, $terms, $author, $date, $primary);

            if ($address !== null) {
                yield $address;
            }
        }
    }
}
