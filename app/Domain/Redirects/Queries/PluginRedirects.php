<?php

/**
 * The redirects one plugin added, for its redirects door: oldest first, each
 * with whether it answers readers now.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Queries;

use App\Domain\Redirects\RedirectState;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Redirects\RedirectRef;

final readonly class PluginRedirects
{
    public function __construct(private RedirectStates $states) {}

    /**
     * Returns the redirects the plugin with this code added, oldest first.
     *
     * @return list<RedirectRef>
     */
    public function of(string $code): array
    {
        return $this->refs(DB::table('redirects')->where('plugin', $code)->orderBy('id')->get());
    }

    /**
     * Returns this plugin's redirect with this ID, or null when it has none.
     */
    public function find(string $code, string $id): ?RedirectRef
    {
        return $this->refs(DB::table('redirects')->where('plugin', $code)->where('id', $id)->get())[0] ?? null;
    }

    /**
     * @param  iterable<object>  $rows
     *
     * @return list<RedirectRef>
     */
    private function refs(iterable $rows): array
    {
        $rows = collect($rows);
        $states = $this->states->of($rows->pluck('id')->map(strval(...))->all());
        $refs = [];

        foreach ($rows as $row) {
            $refs[] = new RedirectRef(
                id: (string) $row->id,
                from: '/'.$row->source,
                to: $row->target === null ? null : (string) $row->target,
                status: (int) $row->status,
                note: (string) $row->note,
                answering: ($states[(string) $row->id]['state'] ?? null) === RedirectState::On,
            );
        }

        return $refs;
    }
}
