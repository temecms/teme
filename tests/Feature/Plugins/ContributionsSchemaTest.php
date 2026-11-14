<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;

it('states the contributions’ types, surfaces, icons and patterns as the validator does', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $contributes = $schema['properties']['contributes']['properties'];
    $setting = $contributes['settings']['items']['properties'];
    $link = $contributes['admin']['items']['properties'];
    // The PHP patterns end with the D modifier, so $ is the true end; JSON Schema's $ already is.
    $between = fn (string $pattern): string => str_ends_with($pattern, 'D') ? substr($pattern, 1, -2) : $pattern;

    expect(array_keys($contributes))->toEqualCanonicalizing(['settings', 'admin', 'csp'])
        ->and($setting['type']['enum'])->toBe(ContributionsValidator::TYPES)
        ->and($link['surface']['enum'])->toBe(ContributionsValidator::SURFACES)
        ->and($link['icon']['enum'])->toBe(ContributionsValidator::ICONS)
        ->and($setting['key']['pattern'])->toBe($between(ContributionsValidator::KEY))
        ->and($link['id']['pattern'])->toBe($between(ContributionsValidator::LINK_ID))
        ->and($link['to']['pattern'])->toBe($between(ContributionsValidator::TO));
});
