<?php

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\AltText\Queries;

use Illuminate\Support\Facades\DB;

/**
 * The alt text kept from before and after a used suggestion (I-1312), for
 * Tools › AI activity's drawer.
 */
final class AltTextChanges
{
    /**
     * Returns the alt text from before (null when there was none) and after
     * the suggestion of this request was used, or null when the request kept
     * no change.
     *
     * @return array{before: string|null, after: string}|null
     */
    public function for(string $activityId): ?array
    {
        $row = DB::table('ai_alt_text_changes')->where('activity_id', $activityId)->first(['alt_before', 'alt_after']);

        return $row === null ? null : [
            'before' => $row->alt_before === null ? null : (string) $row->alt_before,
            'after' => (string) $row->alt_after,
        ];
    }
}
