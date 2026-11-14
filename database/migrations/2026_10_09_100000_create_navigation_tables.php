<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * docs/architecture/themes.md, "Navigation": the menus editors build under
 * Content › Navigation (spec §5.9), as trees of items. An item points at its
 * target by ID (rule 9), never by its address, which is made when the menu
 * is drawn; only an outside address is stored as typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            // Made from the name once, when the menu is created, and never changed.
            $table->string('handle', 60)->collation('C')->unique();
            $table->timestampsTz();
        });

        Schema::create('navigation_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('navigation_id')->constrained('navigations')->cascadeOnDelete();
            $table->ulid('parent_id')->nullable();
            $table->smallInteger('position');
            $table->string('kind', 16);
            // A content item's or a term's ID: either table, so no foreign key.
            $table->string('target_id', 26)->nullable();
            $table->text('url')->nullable();
            // Null means the target's own title or name.
            $table->string('label', 200)->nullable();
            $table->boolean('opens_new_tab')->default(false);
            $table->timestampsTz();

            $table->index(['navigation_id', 'parent_id', 'position']);
        });

        // A second step, as for terms: PostgreSQL refuses a foreign key to a
        // column the create statement hasn't made unique yet.
        Schema::table('navigation_items', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('navigation_items')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_kind_check CHECK (kind IN ('content', 'term', 'home', 'url'))");
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_url_check CHECK (kind <> 'url' OR url IS NOT NULL)");
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_target_check CHECK (kind NOT IN ('content', 'term') OR target_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_items');
        Schema::dropIfExists('navigations');
    }
};
