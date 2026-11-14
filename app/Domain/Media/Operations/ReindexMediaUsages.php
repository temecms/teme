<?php

declare(strict_types=1);

namespace App\Domain\Media\Operations;

use App\Domain\Contents\LinkProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Media\MediaProjection;
use App\Domain\Media\Operations\Input\ReindexMediaUsagesInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Re-derives the media-usage and content-link projections for a chunk of
 * content from its revisions (spec §5.3: projections can always be rebuilt).
 * The operator only, one chunk per transaction.
 */
final class ReindexMediaUsages extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator re-derives media usage and links.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * The links added and removed across the chunk. content_links has no
     * counter, so these rows are the report.
     *
     * @return array{added: int, removed: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ReindexMediaUsagesInput $input */
        $contentIds = $input->contentIds;
        $links = ['added' => 0, 'removed' => 0];

        foreach (Content::withTrashed()->whereIn('id', $contentIds)->orderBy('id')->lockForUpdate()->get() as $content) {
            MediaProjection::sync($content);
            $result = LinkProjection::sync($content);
            $links['added'] += $result['added'];
            $links['removed'] += $result['removed'];
        }

        return $links;
    }
}
