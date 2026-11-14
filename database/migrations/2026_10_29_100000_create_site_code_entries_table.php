<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Settings › Site code (I-288): the code administrators add to every public
 * page, one row an entry, kept exactly as written. Read whole and small, so
 * it needs no index of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_code_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('placement', 16);
            $table->text('code');
            $table->boolean('enabled');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        // The document shell's three placements (App\Domain\Site\Document\Placement).
        DB::statement("ALTER TABLE site_code_entries ADD CONSTRAINT site_code_entries_placement_check CHECK (placement IN ('head', 'body-start', 'body-end'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('site_code_entries');
    }
};
