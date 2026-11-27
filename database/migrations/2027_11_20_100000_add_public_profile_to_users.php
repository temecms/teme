<?php

/**
 * Migration: gives an account the opt-in facts its public author page may show.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * One JSONB value on the account's own row, beside its bio: a role line, a
 * coverage line and a few links, each written only when the person fills it.
 * Null while they have filled none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN public_profile jsonb NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_public_profile_object CHECK (public_profile IS NULL OR jsonb_typeof(public_profile) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_public_profile_object');
        DB::statement('ALTER TABLE users DROP COLUMN public_profile');
    }
};
