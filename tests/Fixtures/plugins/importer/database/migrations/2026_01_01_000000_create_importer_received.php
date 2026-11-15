<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importer_received', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->bigInteger('source_id');
            $table->string('type', 40)->nullable();
            $table->jsonb('payload');
            $table->unique(['kind', 'source_id']);
        });
    }
};
