<?php

/**
 * Carries the design group's stored accent into brand once (issue 261): the
 * site's identity colour is no longer the theme accent override.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Domain\Settings\SettingsRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('group_key', 'design')->lockForUpdate()->first(['overrides', 'draft_values']);

        if ($row === null) {
            return;
        }

        /** @var array<string, mixed> $overrides */
        $overrides = json_decode((string) $row->overrides, true) ?? [];
        /** @var array<string, mixed>|null $draft */
        $draft = $row->draft_values === null ? null : (json_decode((string) $row->draft_values, true) ?? []);
        $movedLive = false;
        $movedDraft = false;
        $before = [];

        if (array_key_exists('accent', $overrides) && ! array_key_exists('brand', $overrides)) {
            $before = ['accent' => $overrides['accent']];
            $overrides['brand'] = $overrides['accent'];
            unset($overrides['accent']);
            $movedLive = true;
        }

        if (is_array($draft) && array_key_exists('accent', $draft) && ! array_key_exists('brand', $draft)) {
            $draft['brand'] = $draft['accent'];
            unset($draft['accent']);
            $movedDraft = true;
        }

        if (! $movedLive && ! $movedDraft) {
            return;
        }

        $update = ['updated_at' => now()];

        if ($movedLive) {
            $update['overrides'] = json_encode($overrides);
            $update['version'] = DB::raw('version + 1');
        }

        if ($movedDraft) {
            $update['draft_values'] = json_encode($draft);
        }

        DB::table('settings')->where('group_key', 'design')->update($update);

        if ($movedLive) {
            DB::table('settings_changes')->insert([
                'id' => strtolower((string) Str::ulid()),
                'group_key' => 'design',
                'changed_keys' => json_encode(['brand']),
                'before' => json_encode($before),
                'after' => json_encode(['brand' => $overrides['brand']]),
                'actor_id' => null,
                'origin' => 'system',
                'created_at' => now(),
            ]);
        }

        Cache::memo()->forget(SettingsRepository::cacheKey('design'));

        // Same tag Design publish purges: every page drawn in the design.
        if ($movedLive) {
            app(PageCache::class)->purge([PageTags::THEME]);
        }
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('group_key', 'design')->lockForUpdate()->first(['overrides', 'draft_values']);

        if ($row === null) {
            return;
        }

        /** @var array<string, mixed> $overrides */
        $overrides = json_decode((string) $row->overrides, true) ?? [];
        /** @var array<string, mixed>|null $draft */
        $draft = $row->draft_values === null ? null : (json_decode((string) $row->draft_values, true) ?? []);
        $update = [];
        $movedLive = false;

        if (array_key_exists('brand', $overrides) && ! array_key_exists('accent', $overrides)) {
            $overrides['accent'] = $overrides['brand'];
            unset($overrides['brand']);
            $update['overrides'] = json_encode($overrides);
            $update['version'] = DB::raw('version + 1');
            $movedLive = true;
        }

        if (is_array($draft) && array_key_exists('brand', $draft) && ! array_key_exists('accent', $draft)) {
            $draft['accent'] = $draft['brand'];
            unset($draft['brand']);
            $update['draft_values'] = json_encode($draft);
        }

        if ($update === []) {
            return;
        }

        $update['updated_at'] = now();
        DB::table('settings')->where('group_key', 'design')->update($update);
        Cache::memo()->forget(SettingsRepository::cacheKey('design'));

        if ($movedLive) {
            app(PageCache::class)->purge([PageTags::THEME]);
        }
    }
};
