<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes the fields reference from the registry', function (): void {
    $path = storage_path('framework/testing/fields-reference.md');

    $this->artisan('teme:docs:fields', ['--output' => $path])->assertSuccessful();

    expect(file_get_contents($path))
        ->toContain('# Fields reference')
        ->toContain('`seo_title`')
        ->toContain('01K5Z0F1ED00000000000000S1')
        ->toContain('The title search engines show');
});
