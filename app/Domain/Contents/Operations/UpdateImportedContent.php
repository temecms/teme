<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cache\Footprints;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Concerns\FollowsTree;
use App\Domain\Contents\Operations\Concerns\WritesImportedContent;
use App\Domain\Contents\Operations\Input\UpdateImportedContentInput;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Brings an item an earlier import made up to date with WordPress (I-332),
 * only while it is as the import left it: any save a person made here since
 * moves its fingerprint, and from then on WordPress's changes are left for
 * the report, never written. Only an import's actor may call it.
 */
final class UpdateImportedContent extends BaseOperation
{
    use AuthorizesImports;
    use FollowsTree;
    use WritesImportedContent;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var UpdateImportedContentInput $input */
        $content = Content::withTrashed()->find($input->contentId);

        if ($content === null) {
            throw ValidationException::withMessages(['content' => 'The item to update no longer exists.']);
        }

        if (preg_match('/^[0-9a-f]{40}$/', $input->written) !== 1) {
            throw ValidationException::withMessages(['written' => 'The import’s record of this item couldn’t be read.']);
        }

        $this->treeType = (string) $content->type;

        if ($content->type !== $input->item->type) {
            throw ValidationException::withMessages(['type' => 'Teme can’t change an item’s type.']);
        }

        $stored = $content->working_revision_id === null ? [] : (array) ContentRevision::query()->whereKey($content->working_revision_id)->value('fields');

        ImportedContents::validate((string) $content->id, $input->item, $stored);
    }

    /**
     * @return array{changedHere: bool, wrote: bool, written: string, moved: array{from: string, to: string}|null, taken: int, many: bool, clash: array{from: string, holder: string}|null, links: list<array{from: string, to: string|null}>, kept: list<array{title: string, path: string, address: string, holder: string}>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var UpdateImportedContentInput $input */
        $nests = $this->takeTree();
        $content = Content::withTrashed()->lockForUpdate()->findOrFail($input->contentId);
        $this->noteChain($content, $nests);

        // Checked again under the lock: a person's save between the phase's read and this write wins.
        if (ImportedContents::fingerprint($content) !== $input->written) {
            return ['changedHere' => true, 'wrote' => false, 'written' => $input->written, 'moved' => null, 'taken' => 0, 'many' => false, 'clash' => null, 'links' => [], 'kept' => []];
        }

        $before = ContentState::of($content);
        // What the public saw of it, read under the lock (I-263).
        $shown = Footprints::content($content);
        $result = $this->writeImported($content, $input->item, $input->actorId(), $before);
        $content->refresh();
        // Nobody is there to read a refusal: a page under it whose new address is taken keeps its old one, and the run says so (I-3363).
        $kept = $this->followTree($content, $input->actorId())->kept;

        if ($result['wrote']) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [...$shown, ...Footprints::content($content)]));
        }

        return [
            'changedHere' => false,
            'wrote' => $result['wrote'],
            'written' => ImportedContents::fingerprint($content),
            'moved' => $result['moved'],
            'taken' => $result['taken'],
            'many' => $result['many'],
            'clash' => $result['clash'],
            'links' => $result['links'],
            'kept' => $kept,
        ];
    }
}
