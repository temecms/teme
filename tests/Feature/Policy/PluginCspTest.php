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

it("refuses a plugin's csp unless it inserts content or runs scripts, in these words", function (): void {
    expect(app(ContributionsValidator::class)->errors(['csp' => ['font' => ['fonts.example.com']]], '1.0.0', []))
        ->toBe(['A plugin’s csp is read only with content.insert, pages.insert or site.scripts.'])
        ->and(app(ContributionsValidator::class)->errors(['csp' => ['font' => ['fonts.example.com']]], '1.0.0', ['content.insert']))->toBe([])
        ->and(app(ContributionsValidator::class)->errors(['csp' => ['frame' => ['ads.example.com']]], '1.0.0', ['pages.insert']))->toBe([])
        ->and(app(ContributionsValidator::class)->errors(['csp' => ['connect' => ['api.example.test']]], '1.0.0', ['site.scripts']))->toBe([]);
});
