<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes the fields reference from the registry', function (): void {
    $path = storage_path('framework/testing/fields-reference.md');

    $this->artisan('teme:docs:fields', ['--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))
        ->toContain('# Fields reference')
        ->toContain('`seo_title`')
        ->toContain('01K5Z0F1ED00000000000000S1')
        ->toContain('The title search engines show')
        ->toContain('`insertions_off`')
        ->toContain(BuiltInFields::INSERTIONS_OFF)
        ->toContain('Turns off ads and other content plugins place inside this item.');
});

it('keeps the committed fields reference in step with the command', function (): void {
    $path = storage_path('framework/testing/fields-reference-drift.md');

    $this->artisan('teme:docs:fields', ['--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toBe(file_get_contents(base_path('docs/reference/fields.md')));
});
