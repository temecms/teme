<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_links', function (Blueprint $table): void {
            $table->id();
            $table->string('url');
            $table->timestampsTz();
        });
    }
};
