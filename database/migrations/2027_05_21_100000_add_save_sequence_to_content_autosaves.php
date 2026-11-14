<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which editor session wrote an autosave, and that save's number there, so
 * a save that arrives after a newer one from the same editor is ignored
 * rather than written over it. Nullable, added without a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_autosaves', function (Blueprint $table): void {
            $table->jsonb('save_sequence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_autosaves', function (Blueprint $table): void {
            $table->dropColumn('save_sequence');
        });
    }
};
