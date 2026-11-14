<?php

declare(strict_types=1);

namespace App\Domain\Contents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A link that shows one revision of one article to someone with no account,
 * until it expires or is revoked (I-181, 3A). Only a hash of its token is
 * kept; the token itself is shown once, when the link is made.
 *
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
final class PreviewLink extends Model
{
    use HasUlids;

    protected $table = 'preview_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Content, $this> */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
