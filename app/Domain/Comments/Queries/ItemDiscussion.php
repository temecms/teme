<?php

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Comments\DiscussionSettings;
use App\Domain\Settings\Modules;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * The Publish panel's Discussion row for one item (I-687): its state and the
 * days after publishing that new comments stop.
 */
final readonly class ItemDiscussion
{
    public function __construct(private ContentTypeRegistry $types) {}

    /**
     * The item's state and close_after_days (0 for never); null while
     * comments are switched off, and for an item that is missing, in the
     * trash, or of a type that takes no comments.
     *
     * @return array{value: string, closeAfterDays: int}|null
     */
    public function of(string $contentId): ?array
    {
        if (! Modules::on(Modules::COMMENTS)) {
            return null;
        }

        $row = DB::table('contents')->where('id', $contentId)->whereNull('deleted_at')->first(['type', 'comment_status']);

        if ($row === null) {
            return null;
        }

        $type = (string) $row->type;

        if (! $this->types->has($type) || $this->types->get($type)->comments === null) {
            return null;
        }

        return ['value' => (string) $row->comment_status, 'closeAfterDays' => DiscussionSettings::current()->closeAfterDays];
    }
}
