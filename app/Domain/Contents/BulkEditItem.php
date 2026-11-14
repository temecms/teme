<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Comments\Discussion;
use App\Domain\Comments\Operations\Input\SetDiscussionInput;
use App\Domain\Comments\Operations\SetDiscussion;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Contents\Queries\ContentPointers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One item of a Bulk Edit (I-204): its terms and author through one
 * UpdateContentDetails, then its status through PublishRevision or
 * UnpublishContent, then its Discussion through SetDiscussion. It writes
 * nothing itself; each step is an Operation.
 */
final readonly class BulkEditItem
{
    public function __construct(private ContentPointers $pointers) {}

    /**
     * Applies the edit to one item and returns its line for the report, such
     * as "terms and author changed; already a draft". Throws when nothing
     * could be done, so the caller reports the item as skipped.
     *
     * @param  array{type: string, status: string, workingRevisionId: string|null, terms: list<string>}  $pointer
     * @param  list<string>  $addTerms
     * @param  list<string>  $removeTerms
     */
    public function apply(string $contentId, array $pointer, string $actorId, array $addTerms, array $removeTerms, ?int $authorId, string $status, string $discussion = 'keep'): string
    {
        $terms = array_values(array_diff(array_unique([...$pointer['terms'], ...$addTerms]), $removeTerms));
        $changed = array_keys(array_filter(['terms' => $terms !== $pointer['terms'], 'author' => $authorId !== null]));
        $lines = [];

        if ($changed !== []) {
            app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
                $contentId,
                (string) $pointer['workingRevisionId'],
                $actorId,
                authorId: $authorId,
                terms: in_array('terms', $changed, true) ? $terms : null,
            ));
            $lines[] = __(':details changed', ['details' => implode(' and ', array_map(fn (string $detail): string => __($detail), $changed))]);
        }

        $statusStep = $this->status($contentId, $pointer['status'], $actorId, $status, $changed !== []);

        if ($statusStep !== null) {
            $lines[] = $statusStep['line'];
        }

        $moved = $statusStep !== null && $statusStep['moved'];
        $discussionLine = $this->discussion($contentId, $actorId, $discussion, $changed !== [] || $moved);

        if ($discussionLine !== null) {
            $lines[] = $discussionLine;
        }

        return $lines === [] ? __('nothing to change') : implode('; ', $lines);
    }

    /**
     * Why an item was skipped, in the words the report uses.
     */
    public static function reason(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AuthorizationException => __('You may not change this item.'),
            $exception instanceof ModelNotFoundException => __('This item no longer exists.'),
            $exception instanceof StaleRevisionException => __('Someone changed this item meanwhile.'),
            $exception instanceof ValidationException => (string) collect($exception->errors())->flatten()->first(),
            default => throw $exception,
        };
    }

    /**
     * The status step, or null when the edit leaves the status alone. An item
     * that already has the status is skipped, not re-published or re-unpublished.
     * If the details already changed, a refused status step is reported in the
     * line instead of throwing, because the item did change. It says whether
     * it moved the item, so the Discussion step knows whether anything changed.
     *
     * @return array{line: string, moved: bool}|null
     */
    private function status(string $contentId, string $current, string $actorId, string $wanted, bool $detailsChanged): ?array
    {
        if ($wanted === 'keep') {
            return null;
        }

        if ($wanted === $current) {
            return ['line' => $wanted === 'published' ? __('already published') : __('already a draft'), 'moved' => false];
        }

        try {
            if ($wanted === 'published') {
                // UpdateContentDetails may have written a new working revision.
                $working = (string) $this->pointers->forIds([$contentId])[$contentId]['workingRevisionId'];
                app(PublishRevision::class)->execute(new PublishRevisionInput($contentId, $working, $actorId));

                return ['line' => __('published'), 'moved' => true];
            }

            app(UnpublishContent::class)->execute(new ContentActionInput($contentId, $actorId));

            return ['line' => __('moved to drafts'), 'moved' => true];
        } catch (AuthorizationException|StaleRevisionException|ValidationException $exception) {
            if (! $detailsChanged) {
                throw $exception;
            }

            return ['line' => __('status not changed: :reason', ['reason' => self::reason($exception)]), 'moved' => false];
        }
    }

    /**
     * The Discussion step (I-688), after the status: SetDiscussion, or null
     * when the edit leaves it alone. A refusal is reported in the item's line
     * when something else on the item changed, as the status step's is; when
     * nothing did, it throws, so the item is reported as skipped. Either way
     * SetDiscussion wrote nothing: it refuses before it writes.
     */
    private function discussion(string $contentId, string $actorId, string $wanted, bool $somethingChanged): ?string
    {
        if ($wanted === 'keep') {
            return null;
        }

        try {
            $changed = app(SetDiscussion::class)->execute(new SetDiscussionInput($contentId, $wanted, $actorId)) === true;
        } catch (AuthorizationException|ValidationException $exception) {
            if (! $somethingChanged) {
                throw $exception;
            }

            return __('discussion not changed: :reason', ['reason' => self::reason($exception)]);
        }

        $state = Discussion::from($wanted)->label();

        return $changed
            ? __('discussion set to :state', ['state' => $state])
            : __('discussion already :state', ['state' => $state]);
    }
}
