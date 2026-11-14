<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Old preview links are records, not anyone's work, so they are pruned here,
 * as SecurityLog prunes its events. Recent ones stay, so the writer can
 * still see what they shared.
 */
final class PreviewLinkRetention
{
    /**
     * Deletes the links that expired, or were revoked, more than $days ago,
     * and says how many.
     */
    public function prune(int $days): int
    {
        $before = Date::now()->subDays($days);

        return DB::table('preview_links')
            ->where(fn ($query) => $query->where('expires_at', '<', $before)->orWhere('revoked_at', '<', $before))
            ->delete();
    }
}
