<?php

/**
 * Migration: nests page addresses.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Settings\SettingsRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Pages' stored address pattern gains {parent} (I-3363): a site that saved
 * /{slug}/ or /{slug} gets /{parent}/{slug}/ or /{parent}/{slug}. No address
 * moves, since no page has a parent yet. Recorded as the system's change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->swap(['/{slug}/' => '/{parent}/{slug}/', '/{slug}' => '/{parent}/{slug}']);
    }

    public function down(): void
    {
        $this->swap(['/{parent}/{slug}/' => '/{slug}/', '/{parent}/{slug}' => '/{slug}']);
    }

    /**
     * @param  array<string, string>  $map  the stored value this writes over, and what it writes
     */
    private function swap(array $map): void
    {
        $row = DB::table('settings')->where('group_key', 'addresses')->lockForUpdate()->first(['overrides']);
        $before = $row === null ? null : (json_decode((string) $row->overrides, true)['pattern_page'] ?? null);
        $after = is_string($before) ? ($map[$before] ?? null) : null;

        // A site that never saved it reads the new default; any other pattern is a person's choice and stays.
        if ($after === null) {
            return;
        }

        DB::update("UPDATE settings SET overrides = jsonb_set(overrides, '{pattern_page}', to_jsonb(?::text)), version = version + 1, updated_at = now() WHERE group_key = 'addresses'", [$after]);
        DB::table('settings_changes')->insert([
            'id' => strtolower((string) Str::ulid()),
            'group_key' => 'addresses',
            'changed_keys' => json_encode(['pattern_page']),
            'before' => json_encode(['pattern_page' => $before]),
            'after' => json_encode(['pattern_page' => $after]),
            'actor_id' => null,
            'origin' => 'system',
            'created_at' => now(),
        ]);

        DB::afterCommit(fn () => Cache::memo()->forget(SettingsRepository::cacheKey('addresses')));
    }
};
