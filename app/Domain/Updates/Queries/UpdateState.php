<?php

declare(strict_types=1);

namespace App\Domain\Updates\Queries;

use App\Domain\Updates\Release;
use App\Domain\Updates\TemeVersion;
use App\Domain\Updates\UpdateChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the update check has found (I-?? draft): the stored releases, the ones this site is offered, and when GitHub was last asked.
 */
final class UpdateState
{
    /**
     * Every stored release, newest first.
     *
     * @return list<Release>
     */
    public function releases(): array
    {
        $releases = DB::table('teme_releases')->get()->map(fn (object $row): Release => Release::fromRow($row))->all();
        usort($releases, fn (Release $one, Release $other): int => TemeVersion::compare($other->version, $one->version));

        return $releases;
    }

    /**
     * The stored releases on this site's channel, newest first.
     *
     * @return list<Release>
     */
    public function inChannel(): array
    {
        return array_values(array_filter($this->releases(), fn (Release $release): bool => UpdateChannel::allows($release->version)));
    }

    /**
     * The releases on this channel newer than the one running, newest first; none for an untagged build.
     *
     * @return list<Release>
     */
    public function newer(): array
    {
        if (! TemeVersion::tagged()) {
            return [];
        }

        return array_values(array_filter($this->inChannel(), fn (Release $release): bool => TemeVersion::compare($release->version, TemeVersion::current()) > 0));
    }

    /**
     * The newest release on this channel, whether or not it is newer than the one running.
     */
    public function newest(): ?Release
    {
        return $this->inChannel()[0] ?? null;
    }

    /**
     * The same set as newer(), read without the notes, for the banner on every admin page.
     *
     * @return list<array{version: string, security: bool}>
     */
    public function newerSummaries(): array
    {
        if (! TemeVersion::tagged()) {
            return [];
        }

        $rows = DB::table('teme_releases')->select('version', 'prerelease', 'security')->get()
            ->filter(fn (object $row): bool => UpdateChannel::allows((string) $row->version)
                && TemeVersion::compare((string) $row->version, TemeVersion::current()) > 0)
            ->map(fn (object $row): array => ['version' => (string) $row->version, 'security' => (bool) $row->security])
            ->all();
        usort($rows, fn (array $one, array $other): int => TemeVersion::compare($other['version'], $one['version']));

        return $rows;
    }

    /**
     * When GitHub was last asked and how it went, or null before the first ask.
     *
     * @return array{checkedAt: CarbonImmutable, succeededAt: ?CarbonImmutable, error: ?string, etag: ?string, tokenSet: bool}|null
     */
    public function check(): ?array
    {
        $row = DB::table('update_checks')->where('id', 1)->first();

        if ($row === null) {
            return null;
        }

        return [
            'checkedAt' => CarbonImmutable::parse((string) $row->checked_at),
            'succeededAt' => $row->succeeded_at === null ? null : CarbonImmutable::parse((string) $row->succeeded_at),
            'error' => $row->error === null ? null : (string) $row->error,
            'etag' => $row->etag === null ? null : (string) $row->etag,
            'tokenSet' => (bool) $row->token_set,
        ];
    }
}
