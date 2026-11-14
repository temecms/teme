<?php

declare(strict_types=1);

namespace App\Domain\Redirects\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Domain\Redirects\RedirectSource;
use App\Domain\Redirects\RedirectStatus;
use App\Domain\Redirects\RedirectTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Every redirect written out (I-872): as CSV, to edit and import again, and
 * as nginx rules, for a server in front of the site. Items are written at
 * their current addresses and files at their public ones, read 500
 * redirects at a time, so a million never sit in memory at once.
 */
final readonly class RedirectExport
{
    /** How many redirects are read at a time. */
    private const int BATCH = 500;

    /**
     * Returns the CSV file's lines, each ending in a newline: a header, then
     * from, to, status, note and on for every redirect.
     *
     * @return iterable<string>
     */
    public function csv(): iterable
    {
        yield "from,to,status,note,on\n";

        foreach ($this->batches() as [$rows, $targets]) {
            foreach ($rows as $row) {
                yield implode(',', array_map(self::cell(...), [
                    '/'.$row->source,
                    $targets[(string) $row->id] ?? '',
                    (string) $row->status,
                    (string) $row->note,
                    (bool) $row->enabled ? 'yes' : 'no',
                ]))."\n";
            }
        }
    }

    /**
     * Returns nginx's lines, each ending in a newline: two notes, then a
     * `location` for every redirect that is on and not waiting, matching its
     * source in any case, with or without a trailing slash.
     *
     * @return iterable<string>
     */
    public function nginx(): iterable
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        yield __('# Redirects from :host, exported by Teme on :date. Redirects that are switched off, or wait for their item or file, are left out.', ['host' => $host, 'date' => now()->format('j F Y')])."\n";
        yield __('# Items are written at their current addresses: export again after one moves.')."\n";

        foreach ($this->batches() as [$rows, $targets]) {
            foreach ($rows as $row) {
                $status = RedirectStatus::from((int) $row->status);
                $target = $targets[(string) $row->id] ?? null;

                if (! (bool) $row->enabled || (! $status->sendsNowhere() && $target === null)) {
                    continue;
                }

                preg_match_all(RedirectSource::PLACEHOLDER, (string) $row->source, $holders);
                $regex = '^/'.substr(RedirectSource::compile((string) $row->source), 1, -1).'/?$';
                $to = $status->sendsNowhere() ? '' : ' '.self::quoted(self::numbered((string) $target, $holders[2]));

                yield sprintf('location ~* %s { return %d%s; }', self::quoted($regex), $status->value, $to)."\n";
            }
        }
    }

    /**
     * The redirects 500 at a time by ID, each batch with where its redirects
     * send readers now: an address as kept, an item's current address, a
     * file's public one; none for a redirect waiting or answering 410 or 451.
     *
     * @return iterable<array{0: Collection<int, stdClass>, 1: array<string, string>}>
     */
    private function batches(): iterable
    {
        $after = '';

        do {
            $rows = DB::table('redirects')->where('id', '>', $after)->orderBy('id')->limit(self::BATCH)
                ->get(['id', 'source', 'status', 'target_kind', 'target', 'note', 'enabled']);
            $items = $this->itemAddresses($rows->where('target_kind', RedirectTarget::CONTENT)->pluck('target')->map(strval(...))->all());
            $media = $this->fileAddresses($rows->where('target_kind', RedirectTarget::MEDIA)->pluck('target')->map(strval(...))->all());
            $targets = [];

            foreach ($rows as $row) {
                $found = match ($row->target_kind) {
                    RedirectTarget::ADDRESS, RedirectTarget::OUTSIDE => (string) $row->target,
                    RedirectTarget::CONTENT => $items[(string) $row->target] ?? null,
                    RedirectTarget::MEDIA => $media[(string) $row->target] ?? null,
                    default => null,
                };

                if ($found !== null) {
                    $targets[(string) $row->id] = $found;
                }
            }

            yield [$rows, $targets];
            $after = (string) ($rows->last()->id ?? '');
        } while ($rows->count() === self::BATCH);
    }

    /**
     * Each live item's address now, on this site, in one statement.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    private function itemAddresses(array $ids): array
    {
        $settings = AddressSettings::current();
        $addresses = [];

        $rows = DB::table('contents as c')
            // c.id is char(26) and p.target_id varchar: cast c.id, or the cast lands on paths and no index serves the join.
            ->join('paths as p', fn ($join) => $join->on('p.target_id', '=', DB::raw('c.id::text'))->where('p.target_type', RedirectTarget::CONTENT)->where('p.is_current', true))
            ->whereIn('c.id', $ids)->where('c.status', 'published')->whereNull('c.deleted_at')
            ->whereNotNull('c.published_revision_id')->where('c.published_at', '<=', now())
            ->get(['c.id', 'c.type', 'p.path']);

        foreach ($rows as $row) {
            $addresses[(string) $row->id] = $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId === (string) $row->id
                ? '/'
                : PublicRequestPath::encode(Addresses::toUrl((string) $row->path, (string) $row->type));
        }

        return $addresses;
    }

    /**
     * Each file's public address now, in one statement; none for a trashed
     * file or one with no public file yet.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    private function fileAddresses(array $ids): array
    {
        $addresses = [];

        foreach (Media::query()->whereIn('id', $ids)->get() as $media) {
            $url = $media->isImage() ? MediaUrls::largest($media->variants) : MediaDisks::public()->url($media->original_path);

            if ($url !== null) {
                $addresses[$media->id] = $url;
            }
        }

        return $addresses;
    }

    /**
     * One CSV cell: a `'` before a first character a spreadsheet would run
     * as a formula, then quoted when it holds a comma, a quote or a line
     * break, its quotes doubled.
     */
    private static function cell(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            $value = "'".$value;
        }

        return strpbrk($value, ",\"\r\n") === false ? $value : '"'.str_replace('"', '""', $value).'"';
    }

    /**
     * A target for nginx: its placeholders as the source's numbered
     * captures, and a `$` of its own percent-encoded, since nginx reads `$`
     * as a variable.
     *
     * @param  list<string>  $names
     */
    private static function numbered(string $target, array $names): string
    {
        return (string) preg_replace_callback(
            RedirectSource::PLACEHOLDER,
            fn (array $holder): string => '$'.((int) array_search($holder[2], $names, true) + 1),
            str_replace('$', '%24', $target),
        );
    }

    /**
     * A value in nginx's double quotes: a backslash and a quote escaped.
     */
    private static function quoted(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
