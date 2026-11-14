<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Which revision a signed-in person's Preview shows: an item's working
 * revision, its latest saved version, and whether this person may see it,
 * which is whether they may edit the item (I-409). It reads only.
 */
final class WorkingPreview
{
    /**
     * The item and its working revision, and whether this person may edit
     * it; null when there is nothing to show: no such item or person, an
     * item in the trash, or one with no working revision.
     *
     * @return array{contentId: string, revisionId: string, canEdit: bool}|null
     */
    public function for(string $contentId, string $viewerId): ?array
    {
        $content = Content::query()->find($contentId);
        $viewer = User::query()->find($viewerId);

        if ($content === null || $viewer === null || $content->working_revision_id === null) {
            return null;
        }

        return [
            'contentId' => (string) $content->id,
            'revisionId' => (string) $content->working_revision_id,
            'canEdit' => Gate::forUser($viewer)->allows('update', $content),
        ];
    }
}
