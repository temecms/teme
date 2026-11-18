<?php

/**
 * Writes a WordPress post or page as a new Teme item, as WordPress had it
 * (I-332): its row, one revision of the import's, its schedule, review or
 * trash, its projections, its address and its old addresses. Only an
 * import's actor may call it, and it writes no security log per item.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cache\Footprints;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Concerns\WritesImportedContent;
use App\Domain\Contents\Operations\Input\ImportContentInput;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Languages\LanguageLock;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

final class ImportContent extends BaseOperation
{
    use AuthorizesImports;
    use WritesImportedContent;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportContentInput $input */
        if (Content::withTrashed()->whereKey($input->contentId)->exists()) {
            throw ValidationException::withMessages(['content' => 'An item with this ID already exists.']);
        }

        ImportedContents::validate($input->contentId, $input->item, []);
    }

    /**
     * @return array{contentId: string, written: string, moved: array{from: string, to: string}|null, taken: int, many: bool, clash: array{from: string, holder: string}|null, links: list<array{from: string, to: string|null}>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportContentInput $input */
        $item = $input->item;

        // An item given a language takes the languages' lock first (I-3360).
        if ($item->language !== null) {
            LanguageLock::shared();
        }

        $content = new Content;
        // Its dates are WordPress's, never the moment of the import.
        $content->timestamps = false;
        $content->forceFill([
            'id' => $input->contentId,
            'type' => $item->type,
            'language' => $item->language,
            'slug' => $item->slug,
            'status' => 'draft',
            'title' => $item->title,
            'author_id' => (int) $item->authorId,
            'comment_status' => $item->discussion,
            'fields' => [],
            'deleted_at' => $item->trashedAt,
            'created_at' => $item->createdAt,
            'updated_at' => $item->modifiedAt,
        ])->save();

        $result = $this->writeImported($content, $item, $input->actorId(), null);
        $content->refresh();

        $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, Footprints::content($content)));

        return [
            'contentId' => (string) $content->id,
            'written' => ImportedContents::fingerprint($content),
            'moved' => $result['moved'],
            'taken' => $result['taken'],
            'many' => $result['many'],
            'clash' => $result['clash'],
            'links' => $result['links'],
        ];
    }
}
