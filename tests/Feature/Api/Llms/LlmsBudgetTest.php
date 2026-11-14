<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\LlmsPosts;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

/**
 * How many statements one draw of /llms.txt takes, after two to warm the registries.
 */
function llmsStatements(): int
{
    addrGet('/llms.txt')->assertOk();
    addrGet('/llms.txt')->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    addrGet('/llms.txt')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('writes llms.txt in at most 4 statements and one more a section, the same for 1 item and 100', function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Switches::llmsOn();
    $author = User::factory()->create()->assignRole('author');
    $sections = 2;
    addrPublished(User::factory()->create()->assignRole('administrator'), 'About', 'page');
    $bound = 4 + $sections;

    LlmsPosts::many($author, 1);
    $one = llmsStatements();

    LlmsPosts::many($author, 99);
    $many = llmsStatements();

    expect($one)->toBeLessThanOrEqual($bound)->and($many)->toBe($one)
        ->and(count(app(ContentTypeRegistry::class)->all()))->toBeGreaterThan(0);
});
