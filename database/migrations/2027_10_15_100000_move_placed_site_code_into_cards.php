<?php

/**
 * Migration: moves site code placed with a card into the cards themselves.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * A region's Custom HTML card held only the ID of a Site code entry "placed
 * with a card"; it now holds its own code. Every region revision and
 * autosave, history too so a restore still validates, gets the placed
 * entry's code, hosts and whether it was on; a card naming an entry that is
 * gone, or not placed, holds none. The placed entries are then deleted, each
 * logged with its code, the placement check goes back to three places, and
 * one event, and the migration's output, say how many moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var array<string, object{code: string, origins: string|null, enabled: bool}> $entries */
        $entries = DB::table('site_code_entries')->where('placement', 'placed')->get(['id', 'name', 'code', 'origins', 'enabled'])->keyBy('id')->all();
        $cards = [];
        $uses = [];

        foreach (['content_revisions', 'content_autosaves'] as $table) {
            DB::table($table)
                ->select(["{$table}.id", "{$table}.document"])
                ->join('contents', 'contents.id', '=', "{$table}.content_id")
                ->where('contents.type', 'region')
                ->chunkById(100, function ($rows) use ($table, $entries, &$cards, &$uses): void {
                    foreach ($rows as $row) {
                        $document = json_decode((string) $row->document, true);

                        if (! is_array($document)) {
                            continue;
                        }

                        $changed = false;
                        $document = $this->moved($document, $entries, $cards, $uses, $changed);

                        if ($changed) {
                            DB::update("UPDATE {$table} SET document = CAST(? AS jsonb) WHERE id = ?", [json_encode($document, JSON_THROW_ON_ERROR), $row->id]);
                        }
                    }
                }, "{$table}.id", 'id');
        }

        $now = now();

        foreach ($entries as $id => $entry) {
            DB::table('security_events')->insert([
                'type' => 'site_code.deleted',
                'subject' => 'none',
                'user_id' => null,
                'ip' => '',
                'hour' => $now->copy()->startOfHour(),
                'context' => json_encode([
                    'entry' => (string) $id,
                    'before_name' => (string) $entry->name,
                    'before_placement' => 'placed',
                    'before_on' => (bool) $entry->enabled,
                    'before_code' => (string) $entry->code,
                    'before_origins' => implode(' ', (array) json_decode((string) ($entry->origins ?? '[]'), true)),
                    'moved_to_cards' => true,
                ], JSON_THROW_ON_ERROR),
                'first_at' => $now,
                'last_at' => $now,
                'counted' => false,
                'count' => 1,
            ]);
        }

        DB::table('site_code_entries')->where('placement', 'placed')->delete();
        DB::statement('ALTER TABLE site_code_entries DROP CONSTRAINT site_code_entries_placement_check');
        DB::statement("ALTER TABLE site_code_entries ADD CONSTRAINT site_code_entries_placement_check CHECK (placement IN ('head', 'body-start', 'body-end'))");

        $counts = ['moved' => 0, 'moved_off' => 0, 'left_empty' => 0];

        foreach ($cards as $kind) {
            $counts[$kind]++;
        }

        Cache::forget('site-code:on:2');

        // A site that placed no code with a card has nothing to say.
        if ($entries === [] && $cards === []) {
            return;
        }

        $shared = count(array_filter($uses, fn (array $ids): bool => count($ids) > 1));
        $context = [...$counts, 'entries' => count($entries), 'shared_by_more_than_one_card' => $shared];

        DB::table('security_events')->insert([
            'type' => 'site_code.moved_to_cards',
            'subject' => 'none',
            'user_id' => null,
            'ip' => '',
            'hour' => $now->copy()->startOfHour(),
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'first_at' => $now,
            'last_at' => $now,
            'counted' => false,
            'count' => 1,
        ]);

        if (! app()->runningUnitTests()) {
            fwrite(STDOUT, sprintf(
                "  Site code placed with a card moved into the cards: %d card(s) now hold their code, %d of them off; %d held none; %d entr%s removed from Settings › Site code.\n",
                $counts['moved'] + $counts['moved_off'],
                $counts['moved_off'],
                $counts['left_empty'],
                count($entries),
                count($entries) === 1 ? 'y' : 'ies',
            ));
        }
    }

    public function down(): void
    {
        // The cards keep their code; only the wider check comes back.
        DB::statement('ALTER TABLE site_code_entries DROP CONSTRAINT site_code_entries_placement_check');
        DB::statement("ALTER TABLE site_code_entries ADD CONSTRAINT site_code_entries_placement_check CHECK (placement IN ('head', 'body-start', 'body-end', 'placed'))");
    }

    /**
     * The node with each Custom HTML card's entry ID replaced by its entry's
     * code, at any depth, noting what each card, by its ID, became and
     * which cards each entry went to.
     *
     * @param  array<mixed>  $node
     * @param  array<string, object{code: string, origins: string|null, enabled: bool}>  $entries
     * @param  array<string, 'moved'|'moved_off'|'left_empty'>  $cards
     * @param  array<string, array<string, true>>  $uses
     *
     * @return array<mixed>
     */
    private function moved(array $node, array $entries, array &$cards, array &$uses, bool &$changed): array
    {
        if (($node['type'] ?? null) === 'site-code' && is_array($node['attrs'] ?? null) && array_key_exists('entryId', $node['attrs'])) {
            $attrs = $node['attrs'];
            $entryId = is_string($attrs['entryId']) ? $attrs['entryId'] : '';
            $entry = $entries[$entryId] ?? null;
            unset($attrs['entryId']);

            $attrs['code'] = $entry === null ? null : (string) $entry->code;
            $attrs['origins'] = $entry === null ? [] : array_values((array) json_decode((string) ($entry->origins ?? '[]'), true));
            $attrs['on'] = $entry === null ? true : (bool) $entry->enabled;
            $node['attrs'] = $attrs;
            $changed = true;

            $card = is_string($attrs['id'] ?? null) ? $attrs['id'] : '';
            $cards[$card] = $entry === null ? 'left_empty' : ($entry->enabled ? 'moved' : 'moved_off');

            if ($entry !== null) {
                $uses[$entryId][$card] = true;
            }
        }

        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $index => $child) {
                if (is_array($child)) {
                    $node['content'][$index] = $this->moved($child, $entries, $cards, $uses, $changed);
                }
            }
        }

        return $node;
    }
};
