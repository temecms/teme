<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * How one request ended, and the word people read for it (I-1307).
 * A request is recorded suggested, discarded, failed or refused; a suggestion
 * is settled later as used, discarded or out of date. A plugin's request
 * through SiteAi is recorded answered: its answer went to the plugin.
 */
enum AiOutcome: string
{
    case Suggested = 'suggested';
    case Applied = 'applied';
    case Discarded = 'discarded';
    case Failed = 'failed';
    case Refused = 'refused';
    case Stale = 'stale';
    case Answered = 'answered';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Suggested',
            self::Applied => 'Used',
            self::Discarded => 'Discarded',
            self::Failed => 'Failed',
            self::Refused => 'Refused',
            self::Stale => 'Out of date',
            self::Answered => 'Answered',
        };
    }
}
