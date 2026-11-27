<?php

/**
 * When design.site_icon changes: purge every cached page (every head shows
 * the icon), and queue favicon sizes for a newly chosen raster image, as
 * DefaultImageCard does for the default social image (issue 388). A change
 * no person made queues nothing; teme:media:regenerate makes sizes a chosen
 * icon still lacks.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\PublicChanges;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\Queries\SiteIconUse;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;

final class SiteIconSizes
{
    public function handle(SettingsChanged $change): void
    {
        if ($change->group !== SiteIconUse::GROUP || ! in_array(SiteIconUse::KEY, $change->keys, true)) {
            return;
        }

        app(PublicChanges::class)->add(new CacheKeysFor(CacheKeysFor::SETTINGS, SiteIconUse::GROUP, [PageTags::EVERYTHING]));

        if ($change->actorId === null) {
            return;
        }

        $id = SiteIconUse::chosen();

        if ($id === null) {
            return;
        }

        $image = DB::table('media')
            ->where('id', $id)
            ->where('kind', 'image')
            ->whereNull('deleted_at')
            ->whereNull('variants->icon-32')
            ->first(['id', 'file_version', 'mime_type']);

        // Raster only: SVG uploads are not sized (issue 388 follow-up).
        if ($image === null || str_contains(strtolower((string) $image->mime_type), 'svg')) {
            return;
        }

        GenerateMediaVariants::dispatch((string) $image->id, (int) $image->file_version, JobActor::person($change->actorId));
    }
}
