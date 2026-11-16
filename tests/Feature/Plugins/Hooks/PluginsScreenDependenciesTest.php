<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PluginFixtures::loadFromOriginal('relay');
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    PluginFixtures::use('relay', 'relay-pro');

    $this->rows = fn (): array => collect($this->actingAs($this->admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name')->all();
});

afterEach(fn () => PluginFixtures::forget());

it('names each kind of notice in the line a plugin asks to send them with', function (): void {
    $words = collect(($this->rows)()['tests/relay']['asks'])->pluck('words');

    expect($words)->toContain('Send notices to people on this site, by email or in their inbox: Email me when an article I started fails; Email me when the article queue stops');
});

it('says which plugins a plugin needs and which need it, by their own names', function (): void {
    $manifest = PluginFixtures::manifest('relay');
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/relay',
        'code' => 'relay',
        'version' => $manifest['version'],
        'consent' => PluginFixtures::agreed($manifest),
    ]);

    $rows = ($this->rows)();

    expect($rows['tests/relay-pro']['requires'])->toBe([['words' => 'Needs Relay, which must stay enabled.', 'new' => false]])
        ->and($rows['tests/relay-pro']['requiredBy'])->toBe([])
        ->and($rows['tests/relay']['requires'])->toBe([])
        ->and($rows['tests/relay']['requiredBy'])->toBe(['Relay Pro']);
});
