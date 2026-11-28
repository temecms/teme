<?php

/**
 * Tests that a plugin's manifest may declare its built stylesheets under
 * contributes.styles, one per card or insertion, and that a bad declaration
 * is refused.
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

/**
 * Contributes with one card, so a card's sheet has a card to name.
 *
 * @param  list<array<string, mixed>>  $styles
 *
 * @return array<string, mixed>
 */
function stylesContributes(array $styles): array
{
    return [
        'cards' => [['id' => 'gallery', 'label' => 'Gallery', 'description' => 'A gallery.']],
        'styles' => $styles,
    ];
}

it('accepts a stylesheet declared for a card or an insertion the plugin has', function (): void {
    $contributes = stylesContributes([
        ['output' => 'card:gallery', 'file' => 'dist/gallery.css'],
        ['output' => 'insertion:banner', 'file' => 'dist/banner.css'],
    ]);

    expect(app(ContributionsValidator::class)->errors($contributes, '1.0.0', ['pages.insert']))->toBe([]);
});

it('refuses a stylesheet for the page, which is not an output this release', function (): void {
    expect(app(ContributionsValidator::class)->errors(stylesContributes([['output' => 'page', 'file' => 'dist/page.css']]), '1.0.0', []))
        ->toContain('A stylesheet’s output must be card:<id> or insertion:<id>.');
});

it('refuses a stylesheet for a card the plugin doesn’t declare', function (): void {
    expect(app(ContributionsValidator::class)->errors(stylesContributes([['output' => 'card:missing', 'file' => 'dist/missing.css']]), '1.0.0', []))
        ->toContain('A stylesheet names a card the plugin doesn’t declare.');
});

it('refuses a second stylesheet for an output that already has one', function (): void {
    $contributes = stylesContributes([
        ['output' => 'card:gallery', 'file' => 'dist/a.css'],
        ['output' => 'card:gallery', 'file' => 'dist/b.css'],
    ]);

    expect(app(ContributionsValidator::class)->errors($contributes, '1.0.0', []))
        ->toContain('Each output can have one stylesheet.');
});

it('refuses a file that is not a .css path inside the package', function (string $file): void {
    expect(app(ContributionsValidator::class)->errors(stylesContributes([['output' => 'card:gallery', 'file' => $file]]), '1.0.0', []))
        ->toContain('A stylesheet must be a .css file inside the plugin’s package.');
})->with([
    'a .js file' => ['dist/page.js'],
    'a leading slash' => ['/dist/page.css'],
    'a backslash' => ['dist\\page.css'],
    'a parent segment' => ['dist/../../x.css'],
    'a trailing newline after .css' => ["dist/page.css\n"],
]);
