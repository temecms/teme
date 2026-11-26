<?php

/**
 * One scheduled publication: which revision of which article goes live, and
 * when (I-181, 4A). It is a row, not a queued job, so a lost job or a
 * redeploy cannot lose it. Pending means not completed, failed or cancelled;
 * a held row is still pending.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * @property Carbon $run_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 */
final class ScheduledPublication extends Model
{
    use HasUlids;

    /** The stored failure reason for a region whose live code changed after it was scheduled. */
    public const string FAILURE_CODE_CHANGED = 'code-changed';

    protected $table = 'scheduled_publications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * The words for a stored failure reason, translated where it is shown. A
     * reason that isn't a known code is returned as stored.
     *
     * @since next
     */
    public static function failureWords(string $reason): string
    {
        return match ($reason) {
            self::FAILURE_CODE_CHANGED => __('the region’s Custom HTML code on the site changed after it was scheduled, and changed code goes live only when a person publishes it. Check the code, then publish it by hand.'),
            default => $reason,
        };
    }

    /**
     * Whether this row may still run: not completed, failed or cancelled.
     */
    public function isPending(): bool
    {
        return $this->completed_at === null && $this->failed_at === null && $this->cancelled_at === null;
    }

    /**
     * Ends the article's pending schedule, if it has one, and says why. The
     * caller holds the lock on the article and clears contents.scheduled_for.
     */
    public static function cancelPendingFor(string $contentId, string $reason): void
    {
        self::query()
            ->where('content_id', $contentId)
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => Date::now(), 'cancel_reason' => $reason]);
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
