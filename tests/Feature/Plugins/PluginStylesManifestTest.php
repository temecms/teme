<?php

/**
 * Tests that a plugin's manifest may declare its built stylesheets under
 * contributes.styles, one per output, and that a bad declaration is refused.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Status\StatusChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(StatusChecks::class, new StatusChecks);
});

it('accepts a stylesheet declared for a card, an insertion or the page', function (): void {
    $contributes = [
        'styles' => [
            ['output' => 'card:gallery', 'file' => 'dist/gallery.css'],
            ['output' => 'insertion:banner', 'file' => 'dist/banner.css'],
            ['output' => 'page', 'file' => 'dist/page.css'],
        ],
    ];

    expect(app(ContributionsValidator::class)->errors($contributes, '1.0.0', []))->toBe([]);
});

it('refuses a stylesheet for an output that is not a card, an insertion or the page', function (): void {
    $contributes = ['styles' => [['output' => 'footer:main', 'file' => 'dist/footer.css']]];

    expect(app(ContributionsValidator::class)->errors($contributes, '1.0.0', []))
        ->toContain('A stylesheet’s output must be card:<id>, insertion:<id> or page.');
});

it('refuses a stylesheet whose file is not a CSS file inside the package', function (): void {
    $contributes = ['styles' => [
        ['output' => 'page', 'file' => 'dist/page.js'],
        ['output' => 'page', 'file' => '../outside.css'],
    ]];

    expect(app(ContributionsValidator::class)->errors($contributes, '1.0.0', []))
        ->toContain('A stylesheet must be a .css file inside the plugin’s package.');
});
