<?php

declare(strict_types=1);

use App\Domain\Ai\AiKeys;
use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Plugins\Console\DeclaredCommands;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Images\ImageServiceDeclaration;
use App\Domain\Plugins\Types\PluginTypesFile;

it('states the contributions’ types, surfaces, icons and patterns as the validator does', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $contributes = $schema['properties']['contributes']['properties'];
    $setting = $contributes['settings']['items']['properties'];
    $link = $contributes['admin']['items']['properties'];
    // The PHP patterns end with the D modifier, so $ is the true end; JSON Schema's $ already is.
    $between = fn (string $pattern): string => str_ends_with($pattern, 'D') ? substr($pattern, 1, -2) : $pattern;

    $column = $contributes['columns']['items']['properties'];

    $feedLink = $contributes['feedLinks']['items']['properties'];

    expect(array_keys($contributes))->toEqualCanonicalizing(['settings', 'admin', 'csp', 'columns', 'feedLinks', 'actions', 'lists', 'fixedLabel', 'aiTasks', 'notices', 'hooks', 'permissions', 'settingsSections', 'area', 'editorPanels', 'editorChecks', 'searchSection', 'sitemaps', 'structuredData', 'imageService', 'styles', 'exports', 'types', 'cards', 'commands'])
        ->and($contributes['types']['pattern'])->toBe($between(PluginTypesFile::PATH))
        ->and($contributes['imageService']['properties']['host']['pattern'])->toBe($between(ImageServiceDeclaration::HOST))
        ->and($contributes['commands']['maxItems'])->toBe(DeclaredCommands::MOST)
        ->and($contributes['commands']['items']['properties']['name']['pattern'])->toBe($between(DeclaredCommands::NAME))
        ->and($contributes['commands']['items']['properties']['arguments']['maxItems'])->toBe(DeclaredCommands::MOST_PARTS)
        ->and($contributes['commands']['items']['properties']['arguments']['items']['properties']['name']['pattern'])->toBe($between(DeclaredCommands::PART))
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
        ->and($feedLink['rel']['enum'])->toBe(ContributionsValidator::FEED_LINK_RELS)
        ->and($feedLink['href']['pattern'])->toBe($between(ContributionsValidator::FEED_LINK_HREF))
        ->and($contributes['feedLinks']['maxItems'])->toBe(ContributionsValidator::MOST_FEED_LINKS)
        ->and($contributes['actions']['maxItems'])->toBe(ContributionsValidator::MOST_ACTIONS)
        ->and($contributes['actions']['items']['properties']['id']['pattern'])->toBe($between(ContributionsValidator::LINK_ID))
        ->and($contributes['lists']['maxItems'])->toBe(ContributionsValidator::MOST_LISTS)
        ->and($contributes['lists']['items']['properties']['id']['pattern'])->toBe($between(ContributionsValidator::LINK_ID))
        ->and($contributes['lists']['items']['properties']['columns']['maxItems'])->toBe(ContributionsValidator::MOST_LIST_COLUMNS)
        ->and($contributes['lists']['items']['properties']['columns']['items']['properties']['kind']['enum'])->toBe(ContributionsValidator::COLUMN_KINDS)
        ->and($contributes['lists']['items']['properties']['rows']['maximum'])->toBe(ContributionsValidator::MOST_LIST_ROWS)
        ->and($contributes['aiTasks']['maxItems'])->toBe(PluginAiTasks::MOST)
        ->and($contributes['aiTasks']['items']['properties']['needs']['items']['enum'])->toBe(PluginAiTasks::NEEDS)
        ->and($contributes['aiTasks']['items']['properties']['sends']['enum'])->toBe(PluginAiTasks::SENDS)
        ->and('/'.$contributes['aiTasks']['items']['properties']['id']['pattern'].'/')->toBe(AiKeys::PATTERN)
        ->and($contributes['cards']['maxItems'])->toBe(ContributionsValidator::MOST_CARDS)
        ->and($contributes['cards']['items']['properties']['id']['pattern'])->toBe($between(ContributionsValidator::CARD_ID))
        ->and($contributes['cards']['items']['properties']['fields']['maxItems'])->toBe(ContributionsValidator::MOST_CARD_FIELDS)
        ->and($contributes['cards']['items']['properties']['fields']['items']['properties']['type']['enum'])->toBe(ContributionsValidator::CARD_FIELD_TYPES)
        ->and($contributes['cards']['items']['properties']['fields']['items']['properties']['key']['pattern'])->toBe($between(ContributionsValidator::KEY));
});
