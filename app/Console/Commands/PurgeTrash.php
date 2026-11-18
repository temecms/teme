<?php

/**
 * teme:trash:purge — deletes for good the content, media and comments that have
 * been in the trash longer than content.trash_days, a chunk at a time, each
 * through DeleteContentPermanently, DeleteMediaPermanently or
 * DeleteCommentPermanently (comments deepest first, 1.8c′). It also keeps
 * abandoned autosaves as revisions and deletes old preview links. Runs daily.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Operations\DeleteCommentPermanently;
use App\Domain\Comments\Operations\Input\CommentActionInput;
use App\Domain\Comments\Queries\TrashedComments;
use App\Domain\Contents\Operations\ArchiveAutosave;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ArchiveAutosaveInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\PreviewLinkRetention;
use App\Domain\Contents\Queries\AbandonedAutosaves;
use App\Domain\Contents\Queries\TrashedContent;
use App\Domain\Media\Operations\DeleteMediaPermanently;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Queries\TrashedMedia;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

final class PurgeTrash extends Command
{
    /** @var string */
    protected $signature = 'teme:trash:purge {--chunk=1000 : Items per chunk}';

    /** @var string */
    protected $description = 'Delete for good the content trashed longer ago than the retention period';

    public function handle(
        TrashedContent $trash,
        DeleteContentPermanently $delete,
        TrashedMedia $trashedMedia,
        DeleteMediaPermanently $deleteMedia,
        TrashedComments $trashedComments,
        DeleteCommentPermanently $deleteComment,
        SettingsReader $settings,
        AbandonedAutosaves $abandoned,
        ArchiveAutosave $archive,
        PreviewLinkRetention $retention,
    ): int {
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $cutoff = now()->subDays($settings->int('content', 'trash_days'));
        $chunk = max(1, (int) $this->option('chunk'));
        $deleted = 0;
        $deletedMedia = 0;

        while (($ids = $trash->olderThan($cutoff, $chunk)) !== []) {
            foreach ($ids as $id) {
                // One whose branch began moving since the list was read waits for the next day.
                try {
                    $delete->execute(new ContentActionInput($id, actorId: null));
                } catch (ModelNotFoundException|ValidationException) {
                    continue;
                }

                $deleted++;
            }
        }

        while (($ids = $trashedMedia->olderThan($cutoff, $chunk)) !== []) {
            foreach ($ids as $id) {
                $deleteMedia->execute(new MediaActionInput($id, actorId: null));
                $deletedMedia++;
            }
        }

        // Comments in the trash as long (1.8c′'s D8), as the operator, deepest
        // first and by keyset: a reply goes before the comment it answers, and
        // one moved or deleted meanwhile is passed over, never read again.
        $deletedComments = 0;
        $tombstones = 0;
        $after = null;

        while (($batch = $trashedComments->olderThan($cutoff, $chunk, $after)) !== []) {
            foreach ($batch as $comment) {
                try {
                    $result = $deleteComment->execute(new CommentActionInput($comment['id'], actorId: null, notChangedAfter: $cutoff));
                } catch (ModelNotFoundException|ValidationException) {
                    continue;
                }

                $deletedComments++;
                $tombstones += $result === CommentStatus::REMOVED ? 1 : 0;
            }

            $after = $batch[array_key_last($batch)];
        }

        // An abandoned autosave is someone's work: it is kept as a revision,
        // never deleted as lost (I-424). A row the Operation skips is newer
        // than the cutoff by then, so the next list does not return it.
        $autosaveCutoff = now()->subDays((int) config('editor.autosave_ttl_days'));
        $archived = 0;

        while (($ids = $abandoned->olderThan($autosaveCutoff, $chunk)) !== []) {
            foreach ($ids as $id) {
                if ($archive->execute(new ArchiveAutosaveInput($id)) !== null) {
                    $archived++;
                }
            }
        }

        // Old preview links are records, not anyone's work.
        $prunedLinks = $retention->prune(30);

        $this->info("Deleted {$deleted} items and {$deletedMedia} media files from the trash.");
        $this->info(trim(trans_choice('Deleted :count comment from the trash.|Deleted :count comments from the trash.', $deletedComments)
            .' '.($tombstones === 0 ? '' : trans_choice(':count tombstone keeps replies in place.|:count tombstones keep replies in place.', $tombstones))));
        $this->info("Kept {$archived} abandoned ".($archived === 1 ? 'autosave' : 'autosaves')." as revisions. Deleted {$prunedLinks} old preview ".($prunedLinks === 1 ? 'link' : 'links').'.');

        return self::SUCCESS;
    }
}
