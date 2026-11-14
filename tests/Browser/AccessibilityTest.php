<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->site = dtSite($this->author);
});

it('passes axe on every template in the light scheme', function (string $what): void {
    visit(dtUrl($this->site, $what))->assertNoAccessibilityIssues(3);
})->with('templates');

it('passes axe on every template in the dark scheme', function (string $what): void {
    designPublish(['theme.default' => ['color_scheme' => 'dark']]);

    visit(dtUrl($this->site, $what))->assertNoAccessibilityIssues(3);
})->with('templates');
