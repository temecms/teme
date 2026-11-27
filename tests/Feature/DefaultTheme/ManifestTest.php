<?php

declare(strict_types=1);

use App\Domain\Design\Contrast;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('is a usable theme with every setting study Q lists', function (): void {
    $theme = app(ThemeRegistry::class)->find('default');

    expect($theme->usable())->toBeTrue()
        ->and(array_column($theme->manifest->settings(), 'key'))->toBe([
            'use_brand_colour', 'header_layout', 'header_color', 'color_scheme', 'latest_header', 'show_featured', 'feed_layout', 'feed_images',
            'featured_image_style', 'show_author_box', 'show_related', 'show_reading_time', 'archive_header', 'show_card_meta',
        ]);
});

it('ships colours that pass contrast in both schemes', function (): void {
    $m = app(ThemeRegistry::class)->find('default')->manifest;

    expect(Contrast::ratio($m->tokens()['contrast'], $m->tokens()['base']))->toBeGreaterThanOrEqual(7.0)
        ->and(Contrast::ratio($m->tokensDark()['contrast'], $m->tokensDark()['base']))->toBeGreaterThanOrEqual(7.0)
        ->and(Contrast::accentProblems($m->tokens()['accent'], $m->tokens()['base']))->toBe([])
        ->and(Contrast::ratio($m->tokensDark()['accent'], $m->tokensDark()['base']))->toBeGreaterThanOrEqual(4.5)
        ->and(Contrast::ratio($m->tokens()['on-accent'], $m->tokens()['accent']))->toBeGreaterThanOrEqual(4.5)
        ->and(Contrast::ratio($m->tokensDark()['on-accent'], $m->tokensDark()['accent']))->toBeGreaterThanOrEqual(4.5)
        // I-457: the theme's own dark accent is the one Teme derives from its light accent,
        // so choosing the theme's red as the site accent changes nothing in dark mode.
        ->and(strtolower($m->tokensDark()['accent']))->toBe(Contrast::darkVariant($m->tokens()['accent'], $m->tokensDark()['base']));
});

it('builds its assets, and teme:theme:check passes within the budgets', function (): void {
    expect(file_exists(base_path('themes/default/public/build/manifest.json')))->toBeTrue();

    // A published item, so the checker draws a single page as well as the home page.
    $this->seed(RoleSeeder::class);
    addrPublished(User::factory()->create()->assignRole('editor'), 'Hello World');

    Artisan::call('teme:theme:check', ['code' => 'default', '--json' => true]);
    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($report['errors'])->toBe([])
        ->and($report['warnings'])->toBe([])
        ->and($report['css'])->toBeLessThanOrEqual(15 * 1024)
        ->and($report['js'])->toBe(0);
});
