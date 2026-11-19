<?php

/**
 * Counts the times a preview link is opened, and when it last was. It is a
 * telemetry sink (I-125), like SecurityLog: people read it and nothing
 * decides anything on it, so it is written here rather than by an
 * Operation. Nothing about the viewer is known or stored.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class PreviewLinkViews
{
    public function record(string $linkId): void
    {
        // The exact instant, microseconds and offset included: the query
        // builder would otherwise format the date to whole seconds.
        DB::table('preview_links')->where('id', $linkId)->update([
            'last_viewed_at' => Date::now()->format('Y-m-d H:i:s.uP'),
            'view_count' => DB::raw('view_count + 1'),
        ]);
    }
}
