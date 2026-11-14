<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leaves the deploy's rebuild step nothing to read, so a test sees a
     * step after the migrations fail.
     */
    public function up(): void
    {
        Schema::drop('release_rebuilds');
    }
};
