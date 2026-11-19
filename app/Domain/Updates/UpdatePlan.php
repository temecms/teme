<?php

/**
 * Which update of Teme may be asked for (I-3326): a listed release newer than the one running, on this
 * site's channel, or the previous release for a roll back; never across a release that needs maintenance mode.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Updates\Queries\TemeUpdates;
use App\Domain\Updates\Queries\UpdateState;
use App\Domain\Updates\Requests\UpdateKind;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePlan
{
    public function __construct(private UpdateState $state, private TemeUpdates $updates) {}

    /**
     * Why $version can't be asked for now, in words people read, or null when it can. A request named in
     * $ignoring doesn't count as the one already under way.
     */
    public function refusal(UpdateKind $kind, string $version, ?string $ignoring = null): ?string
    {
        $current = TemeVersion::current();

        if (! TemeVersion::tagged()) {
            return 'This build isn’t a release, so it can’t be updated from here.';
        }

        if (DB::table('extension_requests')->whereIn('status', ['requested', 'running', 'waiting'])->exists()) {
            return __('A plugin or theme request is under way. Wait for it to end, or cancel it.');
        }

        $open = $this->updates->open();

        if ($open !== null && $open->id !== $ignoring) {
            return 'An update is already under way.';
        }

        $releases = $this->state->releases();
        $listed = array_values(array_filter($releases, fn (Release $release): bool => $release->version === $version))[0] ?? null;

        if ($kind === UpdateKind::Rollback) {
            $previous = $this->previous();

            if ($previous === null || $previous !== $version) {
                return "Teme {$version} isn’t the release this site ran before, so it can’t be rolled back to.";
            }

            if ($listed === null) {
                return "Teme {$version} is no longer listed on GitHub, so it can’t be deployed again from here.";
            }

            return self::marksBetween($version, $current, $releases) ?? $this->maintenanceRanSince($version);
        }

        if ($listed === null || ! UpdateChannel::allows($version)) {
            return "Teme {$version} isn’t a release this site is offered.";
        }

        if (TemeVersion::compare($version, $current) <= 0) {
            return "Teme {$version} isn’t newer than the {$current} this site runs.";
        }

        return self::marksBetween($current, $version, $releases);
    }

    /**
     * Returns the release this site ran before the one running now, or null when none was deployed as a release.
     */
    public function previous(): ?string
    {
        $rows = DB::table('deploy_runs')->where('exit_code', 0)->where('tagged', true)->orderByDesc('finished_at')->pluck('version');

        foreach ($rows as $version) {
            if (TemeVersion::isRelease((string) $version) && TemeVersion::compare((string) $version, TemeVersion::current()) < 0) {
                return (string) $version;
            }
        }

        return null;
    }

    /**
     * Returns the refusal for the first release after $older, up to and including $newer, that needs maintenance mode, or null.
     *
     * @param  list<Release>  $releases
     */
    public static function marksBetween(string $older, string $newer, array $releases): ?string
    {
        foreach ($releases as $release) {
            if ($release->maintenance && TemeVersion::compare($release->version, $older) > 0 && TemeVersion::compare($release->version, $newer) <= 0) {
                return "Teme {$release->version} needs maintenance mode while it deploys, so this update is done by hand: see How to update.";
            }
        }

        return null;
    }

    /**
     * Returns the refusal when a deploy since $version's last good one ran a migration the release before can't run beside, else null.
     */
    private function maintenanceRanSince(string $version): ?string
    {
        $lastGood = DB::table('deploy_runs')->where('version', $version)->where('exit_code', 0)->max('finished_at');

        $ran = DB::table('deploy_runs')
            ->when($lastGood !== null, fn ($query) => $query->where('finished_at', '>', $lastGood))
            ->whereRaw("jsonb_typeof(report->'migrations'->'needs_maintenance') = 'array' AND jsonb_array_length(report->'migrations'->'needs_maintenance') > 0")
            ->exists();

        return $ran ? "A migration since Teme {$version} can’t run beside it, so rolling back needs a restore: see How to update." : null;
    }
}
