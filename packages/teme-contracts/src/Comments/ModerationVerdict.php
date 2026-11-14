<?php

declare(strict_types=1);

namespace Teme\Contracts\Comments;

/**
 * A moderator's answer for one comment: approved, held for a person to
 * decide, or spam, with one sentence saying why and the signals it saw.
 *
 * @since 0.1.0
 */
final readonly class ModerationVerdict
{
    /** Shown to readers at once. @since 0.1.0 */
    public const string APPROVED = 'approved';

    /** Waits for a moderator. @since 0.1.0 */
    public const string PENDING = 'pending';

    /** Kept out of sight, for a moderator to confirm or undo. @since 0.1.0 */
    public const string SPAM = 'spam';

    /**
     * @param  string  $status  one of the constants above
     * @param  string  $reason  one sentence for moderators
     * @param  array<string, string|int|float|bool>  $signals  what the moderator saw, stored with the comment
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $status,
        public string $reason,
        public array $signals = [],
    ) {}
}
