<?php

/**
 * This browser's own comments on an item, found by its key's hash (1.8b′'s
 * D6, D14): core's script asks for them on a page with a thread, from a
 * browser that has commented, and without this index the read would pass
 * over every comment of each status the item has. Partial: imported
 * comments carry no key.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX comments_token ON comments (commenter_token_hash, content_id) WHERE commenter_token_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS comments_token');
    }
};
