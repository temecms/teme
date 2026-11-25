<?php

/**
 * Migration: removes the Custom HTML cards that were switched off.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A region's Custom HTML card no longer has an on or off: removing it takes
 * its code off the site, and the region's history brings it back. Each card
 * that was switched off (`on` false) is taken out of its region's published
 * and working revisions, in place, with no new revision, so the site and the
 * editor stop carrying code nobody sees. Older revisions keep it, so a
 * restore can bring one back. Each is logged once with the code it held, and
 * the pages of every region that changed are purged afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $removed = [];
        $regions = [];

        DB::table('contents')
            ->where('type', 'region')
            ->orderBy('id')
            ->chunk(100, function ($contents) use (&$removed, &$regions): void {
                foreach ($contents as $content) {
                    $revisions = array_unique(array_filter([$content->published_revision_id, $content->working_revision_id]));

                    foreach ($revisions as $revisionId) {
                        $document = json_decode((string) DB::table('content_revisions')->where('id', $revisionId)->value('document'), true);

                        if (! is_array($document)) {
                            continue;
                        }

                        $found = [];
                        $document = $this->without($document, $found);

                        if ($found === []) {
                            continue;
                        }

                        DB::update('UPDATE content_revisions SET document = CAST(? AS jsonb) WHERE id = ?', [json_encode($document, JSON_THROW_ON_ERROR), $revisionId]);
                        $regions[(string) $content->slug] = true;

                        foreach ($found as $card => $held) {
                            $removed[(string) $content->id.'|'.$card] ??= [...$held, 'region' => (string) $content->slug, 'content' => (string) $content->id, 'card' => $card];
                        }
                    }
                }
            });

        $now = now();

        foreach ($removed as $card) {
            DB::table('security_events')->insert([
                'type' => 'site_code.removed_off',
                'subject' => 'none',
                'user_id' => null,
                'ip' => '',
                'hour' => $now->copy()->startOfHour(),
                'context' => json_encode([
                    'region' => $card['region'],
                    'content' => $card['content'],
                    'card' => $card['card'],
                    'before_code' => $card['code'],
                    'before_origins' => $card['origins'],
                ], JSON_THROW_ON_ERROR),
                'first_at' => $now,
                'last_at' => $now,
                'counted' => false,
                'count' => 1,
            ]);
        }

        // After the change, so no page drawn from before it is kept.
        if ($regions !== []) {
            app(PageCache::class)->purge(array_map(PageTags::region(...), array_keys($regions)));
        }

        if ($removed !== [] && ! app()->runningUnitTests()) {
            fwrite(STDOUT, sprintf(
                "  Custom HTML cards that were switched off removed: %d card(s) from %d region(s); older revisions keep them.\n",
                count($removed),
                count($regions),
            ));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The removal of switched-off Custom HTML cards can’t be undone by a rollback; restore a card from its region’s history.');
    }

    /**
     * The node without any Custom HTML card whose `on` is false, at any
     * depth, noting each by its ID with the code and hosts it held.
     *
     * @param  array<mixed>  $node
     * @param  array<string, array{code: string, origins: string}>  $found
     *
     * @return array<mixed>
     */
    private function without(array $node, array &$found): array
    {
        if (! is_array($node['content'] ?? null)) {
            return $node;
        }

        $kept = [];

        foreach ($node['content'] as $child) {
            $attrs = is_array($child) && is_array($child['attrs'] ?? null) ? $child['attrs'] : [];

            if (is_array($child) && ($child['type'] ?? null) === 'site-code' && ($attrs['on'] ?? true) === false) {
                $id = is_string($attrs['id'] ?? null) ? $attrs['id'] : '';
                $hosts = is_array($attrs['origins'] ?? null) ? array_filter($attrs['origins'], 'is_string') : [];
                $found[$id] = ['code' => is_string($attrs['code'] ?? null) ? $attrs['code'] : '', 'origins' => implode(' ', $hosts)];

                continue;
            }

            $kept[] = is_array($child) ? $this->without($child, $found) : $child;
        }

        $node['content'] = $kept;

        return $node;
    }
};
