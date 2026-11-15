<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

afterEach(fn () => PluginFixtures::forget());

it('leaves a plugin’s tables alone when artisan migrate runs', function (): void {
    PluginFixtures::use('tables');
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/tables',
        'code' => 'tables',
        'version' => PluginFixtures::manifest('tables')['version'],
        'consent' => [
            'capabilities' => PluginFixtures::manifest('tables')['capabilities'] ?? [],
            'hosts' => PluginFixtures::manifest('tables')['hosts'] ?? [],
            'hooks' => PluginFixtures::manifest('tables')['hooks'] ?? [],
        ],
    ]);

    Artisan::call('migrate');

    expect(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('tables_clicks'))->toBeFalse();
});
