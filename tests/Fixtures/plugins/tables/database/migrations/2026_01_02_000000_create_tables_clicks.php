<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tables_link_id')->constrained('tables_links')->cascadeOnDelete();
            $table->timestampTz('clicked_at');
        });
    }
};
