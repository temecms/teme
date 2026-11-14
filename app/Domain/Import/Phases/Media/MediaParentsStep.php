<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Addresses\Paths;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Media\Operations\AttachImportedMedia;
use App\Domain\Media\Operations\Input\AttachImportedMediaInput;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Domain\Redirects\RedirectTarget;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * After posts and pages (I-553): each imported attachment's item records
 * the post or page WordPress uploaded it to as its Uploaded for, never
 * overwritten, and its attachment page answers with a 301 to that post, or
 * to the file by ID when it has none. Two hundred a job, in the map's
 * order; a run carried on runs it again and finds nothing left.
 */
final readonly class MediaParentsStep implements StepPhase
{
    public const int PAGE = 200;

    public function key(): string
    {
        return 'media-parents';
    }

    public function order(): int
    {
        // After posts and pages (500), before the second pass (600).
        return 590;
    }

    public function label(): string
    {
        return 'Media’s posts and pages';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        // Each attachment's item, its parent's item if the posts phase brought it, and any redirect already at its page.
        $rows = DB::select(<<<'SQL'
            SELECT a.object_id, a.teme_id AS media_id, a.facts->>'page_path' AS page_path, a.facts->>'duplicate' AS duplicate,
                   m.uploaded_for, c.id AS parent_id, r.id AS redirect_id, r.origin AS redirect_origin,
                   r.target_kind AS redirect_kind, r.target AS redirect_target
            FROM import_map a
            JOIN media m ON m.id = a.teme_id
            LEFT JOIN import_map p ON p.source_id = a.source_id AND p.object_type = 'post'
                AND p.object_id = (a.facts->>'parent') COLLATE "C" AND p.teme_id IS NOT NULL
            LEFT JOIN contents c ON c.id = p.teme_id
            LEFT JOIN redirects r ON r.source = (a.facts->>'page_path') COLLATE "C"
            WHERE a.source_id = ? AND a.object_type = 'attachment' AND a.teme_id IS NOT NULL AND a.object_id > ?
            ORDER BY a.object_id
            LIMIT ?
            SQL, [$context->sourceId, $cursor ?? '', self::PAGE]);

        $messages = [];

        foreach ($rows as $row) {
            $mediaId = (string) $row->media_id;
            $parentId = $row->parent_id === null ? null : (string) $row->parent_id;

            // An item a person or another attachment made wasn't uploaded for this post.
            if ($parentId !== null && $row->uploaded_for === null && $row->duplicate !== 'true') {
                try {
                    app(AttachImportedMedia::class)->execute(new AttachImportedMediaInput($mediaId, $parentId, $context->actorId()));
                } catch (ValidationException) {
                    // The post was deleted since this job read it: the page's redirect goes to the file instead.
                    $parentId = null;
                }
            }

            $message = $this->redirect($row, $mediaId, $parentId, $context);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return count($rows) < self::PAGE
            ? StepResult::done($messages)
            : StepResult::continueAt((string) $rows[count($rows) - 1]->object_id, $messages);
    }

    /**
     * The attachment page's redirect, to the parent or the file, unless its
     * page has a query, a live address or another redirect holds it; what
     * to say when Teme refuses it, else null.
     */
    private function redirect(object $row, string $mediaId, ?string $parentId, ImportContext $context): ?ImportMessage
    {
        $page = $row->page_path === null ? null : (string) $row->page_path;

        if ($page === null) {
            return null;
        }

        $moving = $row->redirect_id !== null && $parentId !== null
            && $row->redirect_origin === 'import'
            && $row->redirect_kind === RedirectTarget::MEDIA
            && $row->redirect_target === $mediaId;

        if (($row->redirect_id !== null && ! $moving) || ($row->redirect_id === null && Paths::find($page) !== null)) {
            return null;
        }

        try {
            app(ImportRedirect::class)->execute(new ImportRedirectInput(
                from: '/'.$page.'/',
                contentId: $parentId,
                mediaId: $parentId === null ? $mediaId : null,
                replacing: $moving ? $mediaId : null,
                actorId: $context->actorId(),
            ));
        } catch (ValidationException $refused) {
            $reason = (string) (Arr::first(Arr::flatten($refused->errors())) ?? '');

            return new ImportMessage('attachment.page-refused', "The attachment page /{$page}/ couldn’t become a redirect: {$reason}", ['attachment' => (int) $row->object_id]);
        }

        return null;
    }
}
