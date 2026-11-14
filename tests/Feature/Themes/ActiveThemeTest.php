<?php

declare(strict_types=1);

use App\Domain\Themes\ActiveTheme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/broken')]]);
});

it('uses the default theme out of the box', function (): void {
    expect(app(ActiveTheme::class)->get()?->code)->toBe('default');
});

it('uses the chosen theme when it is usable', function (): void {
    themeActivate('good');

    expect(app(ActiveTheme::class)->get()?->code)->toBe('good')
        ->and(View::exists('theme::templates.index'))->toBeTrue();
});

it('reads nothing from the database while the application boots', function (): void {
    // `php artisan migrate` boots the application before the settings table
    // exists, so booting must succeed against a database that isn't there.
    $run = Process::env(['DB_DATABASE' => 'teme_no_such_database'])->timeout(120)->run([PHP_BINARY, base_path('artisan'), 'list']);

    expect($run->successful())->toBeTrue($run->errorOutput().$run->output());
});

it('falls back to core views when the chosen theme is broken, and the site still answers', function (): void {
    themeActivate('broken');
    $editor = User::factory()->create()->assignRole('editor');
    addrPublished($editor, 'Still here');

    expect(app(ActiveTheme::class)->get())->toBeNull();
    addrGet('/still-here/')->assertOk()->assertSee('Still here');
});
