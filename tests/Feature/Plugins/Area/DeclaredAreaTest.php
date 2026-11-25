<?php

declare(strict_types=1);

use App\Domain\Plugins\Area\AreaValidator;
use App\Domain\Plugins\Area\DeclaredArea;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Editor\PanelValidator;
use Illuminate\Support\Arr;
use Tests\Fixtures\Plugins\PluginFixtures;

beforeEach(function (): void {
    $this->contributes = PluginFixtures::manifest('placard')['contributes'];
    $this->area = $this->contributes['area'];
});

it('accepts the fixture’s area and reads it, every field’s defaults filled in', function (): void {
    expect(app(ContributionsValidator::class)->errors($this->contributes, '1.0.0', ['jobs']))->toBe([]);

    $area = DeclaredArea::from($this->area);

    expect($area->label)->toBe('Ads')
        ->and($area->permission)->toBe('manage')
        ->and($area->menu)->toBe('site')
        ->and($area->purges)->toBe(['insertions', 'page-insertions'])
        ->and(array_column($area->pages, 'id'))->toBe(['overview', 'ads'])
        ->and($area->page(null)['id'] ?? null)->toBe('overview')
        ->and($area->page('ads')['id'] ?? null)->toBe('ads')
        ->and($area->page('nowhere'))->toBeNull()
        ->and($area->block('ads', 'ads')['record'] ?? null)->toBe('ad')
        ->and($area->record('ad')['titleField'] ?? null)->toBe('name')
        ->and(array_column($area->fields('ad'), 'key'))->toBe(['name', 'location', 'paragraph', 'versions', 'types', 'terms', 'region'])
        ->and($area->actions('ad'))->toBe(['edit', 'duplicate', 'enable', 'disable', 'delete'])
        ->and($area->offeredActions())->toBe(['prune-now']);

    $versions = array_values(array_filter($area->fields('ad'), fn (array $field): bool => $field['key'] === 'versions'))[0];

    expect([$versions['minItems'], $versions['maxItems'], $versions['itemLabel'], $versions['itemTitle']])->toBe([1, 10, 'Version', 'label'])
        ->and(array_column($versions['fields'], 'key'))->toBe(['label', 'code', 'weight'])
        ->and($versions['fields'][1]['scripts'])->toBeTrue();
});

it('refuses an area out of bounds, in one plain sentence each', function (array $changes, string $error): void {
    $area = $this->area;

    foreach ($changes as [$path, $value]) {
        if ($value === null) {
            Arr::forget($area, $path);
        } else {
            Arr::set($area, $path, $value);
        }
    }

    expect(app(ContributionsValidator::class)->errors([...$this->contributes, 'area' => $area], '1.0.0', ['jobs']))->toContain($error);
})->with([
    'an unknown key' => [[['colour', 'red']], 'An area can’t have colour.'],
    'an unknown icon' => [[['icon', 'rocket']], 'rocket isn’t an icon a plugin can use.'],
    'no label' => [[['label', '']], 'An area needs a label of 1 to 40 characters.'],
    'an unknown menu' => [[['menu', 'top']], 'An area’s menu must be content, site or tools.'],
    'an unknown purge' => [[['purges', ['everything']]], 'An area may purge only insertions and page-insertions.'],
    'no pages' => [[['pages', []]], 'An area needs 1 to 8 pages.'],
    'nine pages' => [[['pages', array_map(fn (int $n): array => ['id' => "page-{$n}", 'label' => "Page {$n}", 'blocks' => [['type' => 'notice', 'id' => 'note', 'text' => 'Hello.']]], range(1, 9))]], 'An area needs 1 to 8 pages.'],
    'a page called records' => [[['pages.0.id', 'records']], 'A page’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter, and not records or settings.'],
    'a page called settings' => [[['pages.0.id', 'settings']], 'A page’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter, and not records or settings.'],
    'nine blocks' => [[['pages.0.blocks', array_map(fn (int $n): array => ['type' => 'notice', 'id' => "note-{$n}", 'text' => 'Hello.'], range(1, 9))]], 'A page needs 1 to 8 blocks.'],
    'an unknown block' => [[['pages.0.blocks.0.type', 'map']], 'A block’s type must be figures, chart, table, actions or notice.'],
    'five figures' => [[['pages.0.blocks.0.items', array_map(fn (int $n): array => ['id' => "f{$n}", 'label' => "F{$n}", 'format' => 'number'], range(1, 5))]], 'A figures block holds 1 to 4 figures.'],
    'an unknown format' => [[['pages.0.blocks.0.items.0.format', 'money']], 'A figure’s format must be number, percent or text.'],
    'a pie chart' => [[['pages.0.blocks.1.kind', 'pie']], 'A chart’s kind must be line or bar.'],
    'four series' => [[['pages.0.blocks.1.series', array_map(fn (int $n): array => ['id' => "s{$n}", 'label' => "S{$n}"], range(1, 4))]], 'A chart has 1 to 3 series.'],
    'an undeclared action' => [[['pages.0.blocks.2.actions', ['launch']]], 'An actions block names 1 to 3 of the plugin’s declared actions.'],
    'a long notice' => [[['pages.0.blocks.3.text', str_repeat('a', 301)]], 'A notice needs text of 1 to 300 characters.'],
    'a table of no record' => [[['pages.1.blocks.0.record', 'banner']], 'A table must name one of the area’s records.'],
    'seven columns' => [[['pages.1.blocks.0.columns', array_map(fn (int $n): array => ['id' => "c{$n}", 'label' => "C{$n}"], range(1, 7))]], 'A table needs 1 to 6 columns.'],
    'four filters' => [[['pages.1.blocks.0.filters', array_map(fn (int $n): array => ['id' => "f{$n}", 'label' => "F{$n}", 'options' => ['a' => 'A']], range(1, 4))]], 'A table has at most 3 filters.'],
    'a page of 101 rows' => [[['pages.1.blocks.0.perPage', 101]], 'A table shows 1 to 100 rows a page.'],
    'an unknown row action' => [[['pages.1.blocks.0.rowActions', ['archive']]], 'A table’s row actions must be edit, duplicate, enable, disable or delete.'],
    'edit in bulk' => [[['pages.1.blocks.0.bulkActions', ['edit']]], 'A table’s bulk actions must be duplicate, enable, disable or delete.'],
    'four records' => [[['records', array_map(fn (int $n): array => ['id' => "ad-{$n}", 'label' => 'Ad', 'plural' => 'Ads', 'titleField' => 'name', 'sections' => [['id' => 'basics', 'label' => 'Basics', 'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'Name']]]]], range(1, 4))]], 'An area holds at most 3 kinds of record.'],
    'nine sections' => [[['records.0.sections', array_map(fn (int $n): array => ['id' => "s{$n}", 'label' => "S{$n}", 'fields' => [['key' => "f{$n}", 'type' => 'text', 'label' => "F{$n}"]]], range(1, 9))]], 'A record’s form needs 1 to 8 sections.'],
    'thirty-one fields' => [[['records.0.sections.0.fields', array_map(fn (int $n): array => ['key' => "f{$n}", 'type' => 'text', 'label' => "F{$n}"], range(1, 31))]], 'A section holds 1 to 30 fields.'],
    'sixty-one fields' => [[['records.0.sections', array_map(fn (int $s): array => ['id' => "s{$s}", 'label' => "S{$s}", 'fields' => array_map(fn (int $n): array => ['key' => "f{$s}_{$n}", 'type' => 'text', 'label' => 'F'], range(1, 21))], range(1, 3))], ['records.0.titleField', 'f1_1']], 'A record’s form holds at most 60 fields, a repeater’s counted.'],
    'an unknown field type' => [[['records.0.sections.0.fields.0.type', 'secret']], 'The field name has a type a form can’t draw.'],
    'a repeater in a repeater' => [[['records.0.sections.1.fields.0.fields.0', ['key' => 'inner', 'type' => 'repeater', 'label' => 'Inner', 'fields' => [['key' => 'a', 'type' => 'text', 'label' => 'A']]]]], 'The field inner has a type a form can’t draw.'],
    'fifty-one items' => [[['records.0.sections.1.fields.0.maxItems', 51]], 'The repeater versions holds between 0 and 50 items, its minItems no more than its maxItems.'],
    'an item title of no field' => [[['records.0.sections.1.fields.0.itemTitle', 'nothing']], 'The repeater versions’s itemTitle must name one of its fields.'],
    'scripts on text' => [[['records.0.sections.0.fields.0.scripts', true]], 'Only a code field may say whether it holds scripts, true or false.'],
    'terms of no taxonomy' => [[['records.0.sections.2.fields.1.taxonomy', null]], 'The field terms must name its taxonomy.'],
    'a condition on a later field' => [[['records.0.sections.0.fields.0.visibleWhen', ['location' => 'after_paragraph']]], 'The field “name” shows when “location” has a value, and “location” must be declared before it.'],
    'a condition on no option' => [[['records.0.sections.0.fields.2.visibleWhen', ['location' => 'sidebar']]], 'The field “paragraph” shows when “location” is “sidebar”, which isn’t one of its options.'],
    'a title of no field' => [[['records.0.titleField', 'title']], 'A record’s titleField must name one of its fields.'],
    'no permission' => [[['permission', 'report']], 'An area must name one of the permissions the plugin declares.'],
]);

it('keeps the schema’s area in step with the validator', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $contributes = $schema['properties']['contributes']['properties'];

    expect($contributes['area']['properties']['menu']['enum'])->toBe(AreaValidator::MENUS)
        ->and($contributes['area']['properties']['icon']['enum'])->toBe(PanelValidator::icons())
        ->and($contributes['area']['properties']['purges']['items']['enum'])->toBe(AreaValidator::PURGES)
        ->and($contributes['area']['properties']['pages']['maxItems'])->toBe(AreaValidator::MOST_PAGES)
        ->and($contributes['area']['properties']['records']['maxItems'])->toBe(AreaValidator::MOST_RECORDS)
        ->and($contributes['permissions']['maxItems'])->toBe(3)
        ->and($contributes['settingsSections']['maxItems'])->toBe(AreaValidator::MOST_SETTINGS_SECTIONS)
        ->and($contributes['settings']['items']['properties']['type']['enum'])->toBe(ContributionsValidator::TYPES);
});

it('reads an area’s icon, and plug when none is named', function (): void {
    expect(DeclaredArea::from($this->area)->icon)->toBe('plug')
        ->and(DeclaredArea::from([...$this->area, 'icon' => 'send'])->icon)->toBe('send');
});
