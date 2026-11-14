<?php

declare(strict_types=1);

use App\Domain\Deployment\ReleaseRebuilds;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A release that needs search rebuilt, as its migration asks (I-3113):
     * first the items to rebuild, since search takes only those whose
     * search_config is NULL or another language's.
     */
    public function up(): void
    {
        DB::table('contents')->update(['search_config' => null]);
        ReleaseRebuilds::ask('search');
    }
};
