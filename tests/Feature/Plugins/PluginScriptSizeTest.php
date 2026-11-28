<?php

/**
 * Tests that the Plugins screen shows how big a plugin's declared scripts are, compressed, and warns
 * past the 20 KB budget without ever blocking the plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');

    // The cardbox fixture, declaring a script for its note card, with the capability it needs.
    $this->scripted = function (string $script): void {
        $cardbox = json_decode((string) file_get_contents(PluginFixtures::path('cardbox').'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        PluginFixtures::use(PluginFixtures::copy('cardbox', [
            'capabilities' => ['pages.insert', 'site.scripts'],
            'contributes' => [...$cardbox['contributes'], 'scripts' => [['output' => 'card:note', 'file' => 'dist/note.js']]],
        ], [], ['dist/note.js' => $script]));
    };

    // The plugins' rows, read by name, as the Plugins screen gets them.
    $this->row = fn (): array => collect($this->actingAs($this->admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name')->all()['tests/cardbox'];
});

afterEach(fn () => PluginFixtures::forget());

it('shows the compressed size of a plugin’s declared scripts on its row', function (): void {
    ($this->scripted)('document.documentElement.dataset.note = "1";');

    expect(($this->row)()['scripts'])->toMatchArray(['over' => false])
        ->and(($this->row)()['scripts']['bytes'])->toBeGreaterThan(0)->toBeLessThan(20 * 1024);
});

it('warns on the row past 20 KB compressed, and never blocks the plugin', function (): void {
    // Random text compresses poorly, so this file is over the budget once compressed.
    ($this->scripted)(base64_encode(random_bytes(60 * 1024)));

    $row = ($this->row)();

    expect($row['scripts'])->toMatchArray(['over' => true])
        ->and($row['scripts']['bytes'])->toBeGreaterThan(20 * 1024)
        ->and($row['state'])->not->toBe('cant_be_used');
});

it('shows nothing on the row for a plugin that declares no scripts', function (): void {
    PluginFixtures::use(PluginFixtures::copy('cardbox'));

    expect(($this->row)()['scripts'])->toBeNull();
});
