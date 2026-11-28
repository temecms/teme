<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listfilters_scores', function (Blueprint $table): void {
            $table->id();
            $table->char('content_id', 26);
            $table->string('state', 20);
        });
    }
};
