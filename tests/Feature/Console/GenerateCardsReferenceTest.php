<?php

declare(strict_types=1);

use App\Domain\Cards\CardRegistry;

it('writes the cards reference from the registry', function (): void {
    $path = storage_path('framework/testing/cards-reference.md');

    $this->artisan('teme:docs:cards', ['--output' => $path])->assertSuccessful();

    $reference = file_get_contents($path);

    expect($reference)
        ->toContain('# Cards reference')
        ->toContain('One image, with a caption, a credit, and alt text for this use.')
        ->toContain('`mediaId`')
        ->toContain('size:26')
        ->toContain('0.1.0');

    foreach (app(CardRegistry::class)->names() as $name) {
        expect($reference)->toContain("`{$name}`");
    }
});
