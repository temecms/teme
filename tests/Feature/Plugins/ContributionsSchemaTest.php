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

    $column = $contributes['columns']['items']['properties'];

    expect(array_keys($contributes))->toEqualCanonicalizing(['settings', 'admin', 'csp', 'columns', 'actions', 'lists'])
        ->and($setting['type']['enum'])->toBe(ContributionsValidator::TYPES)
        ->and($link['surface']['enum'])->toBe(ContributionsValidator::SURFACES)
        ->and($link['icon']['enum'])->toBe(ContributionsValidator::ICONS)
        ->and($setting['key']['pattern'])->toBe($between(ContributionsValidator::KEY))
        ->and($link['id']['pattern'])->toBe($between(ContributionsValidator::LINK_ID))
        ->and($link['to']['pattern'])->toBe($between(ContributionsValidator::TO))
        ->and($column['kind']['enum'])->toBe(ContributionsValidator::COLUMN_KINDS)
        ->and($column['width']['enum'])->toBe(array_keys(ContributionsValidator::COLUMN_WIDTHS))
        ->and($column['id']['pattern'])->toBe($between(ContributionsValidator::COLUMN_ID))
        ->and($column['types']['items']['pattern'])->toBe($between(ContributionsValidator::TYPE_KEY))
        ->and($contributes['actions']['maxItems'])->toBe(ContributionsValidator::MOST_ACTIONS)
        ->and($contributes['actions']['items']['properties']['id']['pattern'])->toBe($between(ContributionsValidator::LINK_ID))
        ->and($contributes['lists']['maxItems'])->toBe(ContributionsValidator::MOST_LISTS)
        ->and($contributes['lists']['items']['properties']['id']['pattern'])->toBe($between(ContributionsValidator::LINK_ID))
        ->and($contributes['lists']['items']['properties']['columns']['maxItems'])->toBe(ContributionsValidator::MOST_LIST_COLUMNS)
        ->and($contributes['lists']['items']['properties']['columns']['items']['properties']['kind']['enum'])->toBe(ContributionsValidator::COLUMN_KINDS)
        ->and($contributes['lists']['items']['properties']['rows']['maximum'])->toBe(ContributionsValidator::MOST_LIST_ROWS);
});
