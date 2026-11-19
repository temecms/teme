<?php

/**
 * Each redirect's state now, for the redirect list (I-857, I-858): a
 * statement for the rows, one for what answers their exact sources, one for
 * their items and one for their files, whatever their number.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Queries;

use App\Domain\Addresses\Paths;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Domain\Redirects\RedirectState;
use App\Domain\Redirects\RedirectTarget;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class RedirectStates
{
    public function __construct(private ContentTypeRegistry $types) {}

    /**
     * Returns each known redirect's state and its words, by ID; an unknown
     * ID is left out. A switched-off redirect is Off whatever else is true;
     * then a live page or old address at its source makes it wait; then its
     * item or file.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{state: RedirectState, label: string}>
     */
    public function of(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('redirects')->whereIn('id', $ids)->get(['id', 'source', 'kind', 'enabled', 'target_kind', 'target']);
        $answered = $this->answered($rows->where('kind', 'exact')->pluck('source')->map(strval(...))->all());
        $items = $this->items($rows->where('target_kind', RedirectTarget::CONTENT)->pluck('target')->map(strval(...))->all());
        // Not files(): the architecture tests read that name as listing a folder.
        $files = $this->mediaStates($rows->where('target_kind', RedirectTarget::MEDIA)->pluck('target')->map(strval(...))->all());
        $states = [];

        foreach ($rows as $row) {
            [$state, $singular] = match (true) {
                ! (bool) $row->enabled => [RedirectState::Off, null],
                isset($answered[$row->source]) => [$answered[$row->source], null],
                $row->target_kind === RedirectTarget::CONTENT => $items[$row->target] ?? [RedirectState::ItemDeleted, null],
                $row->target_kind === RedirectTarget::MEDIA => [$files[$row->target] ?? RedirectState::FileDeleted, null],
                default => [RedirectState::On, null],
            };

            $states[(string) $row->id] = ['state' => $state, 'label' => $state->label($singular)];
        }

        return $states;
    }

    /**
     * Which of these exact sources the site answers itself, and how: a
     * current address whose item is live, a term's or an author's, with an
     * account or without, or a date archive, makes a redirect wait for a
     * page; an old address of those kinds whose target has a current one,
     * for that old address (I-858). A kind a later release adds, which
     * Paths::KINDS doesn't list, or an account's row whose ID is no
     * account's (Paths::account()), is not found here, so a redirect there
     * answers.
     *
     * @param  list<string>  $sources
     *
     * @return array<string, RedirectState>
     */
    private function answered(array $sources): array
    {
        $answered = [];

        foreach ($sources as $source) {
            // PathResolver answers a valid year, month or day, even with nothing in it.
            if (preg_match('#^(\d{4})(?:/(\d{2})(?:/(\d{2}))?)?$#', $source, $date) === 1
                && (! isset($date[2]) || ((int) $date[2] >= 1 && (int) $date[2] <= 12))
                && (! isset($date[3]) || checkdate((int) $date[2], (int) $date[3], (int) $date[1]))) {
                $answered[$source] = RedirectState::WaitingForPage;
            }
        }

        if ($sources === []) {
            return $answered;
        }

        $rows = DB::table('paths as p')
            ->whereIn('p.path', $sources)
            // Whole in parentheses: a raw clause is added as written, and its OR would otherwise leave the path list behind.
            // A live row of each kind of Paths::KINDS that has one, by kind; an old row of any of them.
            // One placeholder per kind, written from the list, so a kind a later release adds joins it.
            ->whereRaw(sprintf(<<<'SQL'
                ((p.is_current AND (p.target_type = 'term' OR p.target_type = 'user' AND p.target_id ~ ? OR p.target_type = 'person' OR p.target_type = 'content' AND EXISTS (
                    SELECT 1 FROM contents c
                    WHERE c.id = p.target_id AND c.status = 'published' AND c.deleted_at IS NULL
                      AND c.published_revision_id IS NOT NULL AND c.published_at <= ?::timestamptz
                )))
                OR (NOT p.is_current AND p.target_type IN (%s) AND (p.target_type <> 'user' OR p.target_id ~ ?) AND EXISTS (
                    SELECT 1 FROM paths q WHERE q.target_type = p.target_type AND q.target_id = p.target_id AND q.is_current
                )))
                SQL, implode(', ', array_fill(0, count(Paths::KINDS), '?'))), [Paths::ACCOUNT_ID, CarbonImmutable::now()->format('Y-m-d H:i:s.uP'), ...Paths::KINDS, Paths::ACCOUNT_ID])
            ->get(['p.path', 'p.is_current']);

        foreach ($rows as $row) {
            $answered[(string) $row->path] = (bool) $row->is_current ? RedirectState::WaitingForPage : RedirectState::WaitingForOldAddress;
        }

        return $answered;
    }

    /**
     * Each of these items that still exists: On while it is live, else
     * waiting for it, with its type's singular.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{0: RedirectState, 1: string|null}>
     */
    private function items(array $ids): array
    {
        $items = [];
        $now = CarbonImmutable::now();

        foreach (DB::table('contents')->whereIn('id', $ids)->get(['id', 'type', 'status', 'deleted_at', 'published_revision_id', 'published_at']) as $row) {
            $live = $row->status === 'published' && $row->deleted_at === null && $row->published_revision_id !== null
                && $row->published_at !== null && CarbonImmutable::parse((string) $row->published_at)->lessThanOrEqualTo($now);
            $type = (string) $row->type;

            $items[(string) $row->id] = $live
                ? [RedirectState::On, null]
                : [RedirectState::WaitingForItem, $this->types->has($type) ? $this->types->get($type)->singular : null];
        }

        return $items;
    }

    /**
     * Each of these files that still exists: On while it has a public file,
     * else waiting for it.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, RedirectState>
     */
    private function mediaStates(array $ids): array
    {
        $files = [];

        foreach (Media::withTrashed()->whereIn('id', $ids)->get() as $media) {
            $public = ! $media->trashed() && (! $media->isImage() || MediaUrls::largest($media->variants) !== null);
            $files[$media->id] = $public ? RedirectState::On : RedirectState::WaitingForFile;
        }

        return $files;
    }
}
