<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every public address, one row each (I-061): an item's, a term's or an
 * author's current address, or an old one that answers with a 301.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paths', function (Blueprint $table): void {
            $table->id();
            $table->text('path')->collation('C');
            $table->string('target_type', 16);
            $table->string('target_id', 26);
            $table->boolean('is_current');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('replaced_at')->nullable();

            $table->unique('path');
            $table->index(['target_type', 'target_id']);
        });

        DB::statement('CREATE UNIQUE INDEX paths_one_current ON paths (target_type, target_id) WHERE is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('paths');
    }
};
