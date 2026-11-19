<?php

/**
 * One request for review (decision 5A): the revision a writer handed over,
 * pinned, and what became of it — approved, returned with a note, published
 * by hand while it waited, or withdrawn by the trash. Each submission is its
 * own row, so the history of decisions is kept, not overwritten.
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
 * @property Carbon $submitted_at
 * @property Carbon|null $decided_at
 */
final class ContentReview extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'content_reviews';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Closes the article's pending review, if it has one, with how it ended
     * and who ended it. The caller holds the lock on the article.
     */
    public static function closePendingFor(string $contentId, string $decision, ?string $actorId): void
    {
        self::query()
            ->where('content_id', $contentId)
            ->whereNull('decided_at')
            ->update([
                'decision' => $decision,
                'decided_by' => $actorId === null ? null : (int) $actorId,
                'decided_at' => Date::now(),
            ]);
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
