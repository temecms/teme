<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;

it("reserves a plugin's csp in the schema, in the theme's shape", function (): void {
    $plugin = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $theme = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-theme.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $csp = $plugin['properties']['contributes']['properties']['csp'] ?? null;

    expect($csp)->not->toBeNull()
        ->and(array_keys($csp['properties']))->toBe(['style', 'font', 'img', 'connect', 'frame', 'script'])
        ->and($csp['properties'])->toBe($theme['properties']['csp']['properties'])
        ->and($csp['additionalProperties'])->toBeFalse();
});

it("refuses a plugin's csp until site.output is offered, in these words", function (): void {
    expect(app(ContributionsValidator::class)->errors(['csp' => ['font' => ['fonts.example.com']]], '1.0.0'))
        ->toBe(['A plugin\'s csp is read only with the site.output permission, which Teme doesn\'t offer yet.']);
});
