<?php

/**
 * Migration: validates contents parent.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Validates the parent's two constraints (I-3363). VALIDATE CONSTRAINT takes
 * SHARE UPDATE EXCLUSIVE, so reads and writes go on meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contents VALIDATE CONSTRAINT contents_parent_fk');
        DB::statement('ALTER TABLE contents VALIDATE CONSTRAINT contents_parent_not_self');
    }

    public function down(): void
    {
        // A validated constraint stays valid; the previous migration's down() drops both.
    }
};
