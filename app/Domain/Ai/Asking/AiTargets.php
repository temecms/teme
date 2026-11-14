<?php

declare(strict_types=1);

namespace App\Domain\Ai\Asking;

use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The hash of what a suggestion would change, taken when the job reads it, so
 * Use can tell whether it changed since (I-317): a text block in the revision
 * asked about, or an image's alt text.
 */
final readonly class AiTargets
{
    /** The one thing on an image a suggestion changes. */
    public const string ALT = 'alt';

    /**
     * The target's hash as it was asked about, or null when the suggestion
     * changes no one thing.
     *
     * @throws ModelNotFoundException when the item, its revision or the image is gone
     */
    public function asked(AiSubject $subject): ?string
    {
        if ($subject->target === null) {
            return null;
        }

        if ($subject->type === AiSubjectType::Media) {
            return self::altHash(Media::withTrashed()->findOrFail($subject->id)->alt);
        }

        $revision = ContentRevision::query()->where('content_id', $subject->id)->findOrFail($subject->revisionId);
        $block = TextBlocks::at((array) $revision->document, $subject->target);

        return $block === null ? throw (new ModelNotFoundException)->setModel(ContentRevision::class) : TextBlocks::hash($block);
    }

    /**
     * A field's text in a revision's fields, as a Use compares it with the
     * revision asked about (I-1313): its string, or empty.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fieldText(array $fields, string $key): string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * An image's alt text, hashed as the staleness check compares it.
     */
    public static function altHash(?string $alt): string
    {
        return hash('sha256', (string) $alt);
    }
}
