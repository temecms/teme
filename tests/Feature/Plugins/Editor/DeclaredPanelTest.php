<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Editor\DeclaredPanel;
use App\Domain\Plugins\Editor\PanelValidator;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginWords;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\Linkfinder\LinksPanel;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/Linkfinder.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->manifest = PluginFixtures::manifest('linkfinder');
    $this->contributes = $this->manifest['contributes'];
    $this->errors = fn (array $contributes, array $capabilities = ['content.read', 'jobs']): array => app(ContributionsValidator::class)->errors($contributes, '1.0.0', $capabilities);
});

afterEach(fn () => Linkfinder::reset());

it('accepts the fixture’s panel and reads it as declared', function (): void {
    expect(($this->errors)($this->contributes))->toBe([]);

    $panels = DeclaredPanel::all(new Manifest($this->manifest));

    expect($panels)->toHaveCount(1)
        ->and($panels[0]->id)->toBe('links')
        ->and($panels[0]->label)->toBe('Link suggestions')
        ->and($panels[0]->icon)->toBe('link')
        ->and($panels[0]->types)->toBe(['post'])
        ->and($panels[0]->permission)->toBe('suggest')
        ->and(array_column($panels[0]->actions, 'id'))->toBe(['find', 'rescan'])
        ->and($panels[0]->action('rescan')['confirm'] ?? null)->toBe('This reads every published article again, which takes a minute.')
        ->and(array_column($panels[0]->action('rescan')['fields'] ?? [], 'key'))->toBe(['focus', 'reach', 'most', 'fresh'])
        ->and($panels[0]->action('nothing'))->toBeNull()
        ->and($panels[0]->toolId('linkfinder'))->toBe('plugin.linkfinder.links');
});

it('refuses a panel out of bounds, in one plain sentence each', function (array $changes, string $error): void {
    $contributes = $this->contributes;

    foreach ($changes as [$path, $value]) {
        if ($value === null) {
            Arr::forget($contributes, $path);
        } else {
            Arr::set($contributes, $path, $value);
        }
    }

    expect(($this->errors)($contributes))->toContain($error);
})->with([
    'not a list' => [[['editorPanels', ['links' => ['id' => 'links']]]], 'Its editor panels must be a list.'],
    'three panels' => [[['editorPanels', array_map(fn (int $n): array => ['id' => "panel-{$n}", 'label' => 'Panel', 'types' => ['post']], range(1, 3))]], 'A plugin can add at most 2 editor panels.'],
    'an id in capitals' => [[['editorPanels.0.id', 'Links']], 'An editor panel’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.'],
    'two panels of one id' => [[['editorPanels.1', ['id' => 'links', 'label' => 'Again', 'types' => ['post']]]], 'Two editor panels can’t share the id links.'],
    'an unknown key' => [[['editorPanels.0.colour', 'red']], 'An editor panel can’t have colour.'],
    'no label' => [[['editorPanels.0.label', '']], 'An editor panel’s label must be 1 to 40 characters.'],
    'a long description' => [[['editorPanels.0.description', str_repeat('a', 161)]], 'An editor panel’s description must be text of at most 160 characters.'],
    'an unknown icon' => [[['editorPanels.0.icon', 'rocket']], 'rocket isn’t an icon a plugin can use.'],
    'no types' => [[['editorPanels.0.types', []]], 'An editor panel must name at least one content type.'],
    'a type in capitals' => [[['editorPanels.0.types', ['Post']]], 'An editor panel’s type must be a lower-case content type key.'],
    'a type that isn’t here' => [[['editorPanels.0.types', ['recipe']]], 'Its editor panel names recipe, which isn’t a content type here.'],
    'an undeclared permission' => [[['editorPanels.0.permission', 'write']], 'An editor panel’s permission must be one the plugin declares.'],
    'four actions' => [[['editorPanels.0.actions', array_map(fn (int $n): array => ['id' => "do-{$n}", 'label' => 'Do', 'job' => 'find-links'], range(1, 4))]], 'An editor panel can offer at most 3 actions.'],
    'two actions of one id' => [[['editorPanels.0.actions.1.id', 'find']], 'Two actions of one editor panel can’t share the id find.'],
    'an action of an unknown key' => [[['editorPanels.0.actions.0.surface', 'tools']], 'An editor panel action can’t have surface.'],
    'an action of no label' => [[['editorPanels.0.actions.0.label', '']], 'An editor panel action’s label must be 1 to 40 characters.'],
    'an action of no job' => [[['editorPanels.0.actions.0.job', null]], 'An editor panel action must name the job it queues.'],
    'a long confirmation' => [[['editorPanels.0.actions.1.confirm', str_repeat('a', 201)]], 'An editor panel action’s confirmation must be 1 to 200 characters.'],
    'five fields' => [[['editorPanels.0.actions.1.fields.4', ['key' => 'more', 'type' => 'boolean', 'label' => 'More']]], 'An editor panel action can ask for at most 4 fields.'],
    'a field key in capitals' => [[['editorPanels.0.actions.1.fields.0.key', 'Focus']], 'A panel field’s key must be lower-case letters, digits and underscores, starting with a letter.'],
    'two fields of one key' => [[['editorPanels.0.actions.1.fields.1.key', 'focus']], 'Two fields of one action can’t share the key focus.'],
    'a field of an unknown key' => [[['editorPanels.0.actions.1.fields.0.scripts', true]], 'A panel field can’t have scripts.'],
    'a field of an unknown type' => [[['editorPanels.0.actions.1.fields.0.type', 'url']], 'A panel field’s type must be text, textarea, select, boolean, number or items.'],
    'a field of no label' => [[['editorPanels.0.actions.1.fields.0.label', '']], 'A panel field’s label must be 1 to 40 characters.'],
    'required as a word' => [[['editorPanels.0.actions.1.fields.0.required', 'yes']], 'A panel field’s required must be true or false.'],
    'text over 500' => [[['editorPanels.0.actions.1.fields.0.maxLength', 501]], 'A text field’s maxLength must be from 1 to 500.'],
    'a maxLength on a number' => [[['editorPanels.0.actions.1.fields.2.maxLength', 3]], 'Only a text or textarea field takes a maxLength.'],
    'a select of no options' => [[['editorPanels.0.actions.1.fields.1.options', []]], 'A select field needs its options, each a value and a label.'],
    'options on text' => [[['editorPanels.0.actions.1.fields.0.options', ['a' => 'A']]], 'Only a select field takes options.'],
    'a min on text' => [[['editorPanels.0.actions.1.fields.0.min', 1]], 'Only a number field takes a min, and it must be a number.'],
    'a default out of bounds' => [[['editorPanels.0.actions.1.fields.2.default', 50]], 'A panel field’s default must be a value the field accepts.'],
]);

it('refuses a panel without content.read or jobs, naming which', function (): void {
    expect(($this->errors)($this->contributes, ['jobs']))->toContain('It adds an editor panel, but the plugin doesn’t ask for content.read.')
        ->and(($this->errors)($this->contributes, ['content.read']))->toContain('It adds an editor panel, but the plugin doesn’t ask for jobs.')
        ->and(($this->errors)($this->contributes, ['content.read', 'jobs']))->toBe([]);
});

it('refuses, as the plugin starts, an action’s undeclared job and a class that doesn’t hand over its panels', function (): void {
    $validator = app(PanelValidator::class);
    $jobs = $this->manifest['jobs'];

    expect($validator->startErrors($this->contributes, $jobs, new Linkfinder))->toBe([])
        ->and($validator->startErrors($this->contributes, [], new Linkfinder))->toContain('Its editor panel’s action “find” queues the job “find-links”, which it doesn’t declare.')
        ->and($validator->startErrors($this->contributes, $jobs, new stdClass))->toContain('It declares editor panels but doesn’t implement Teme\\Contracts\\Editor\\HasEditorPanels.');

    Linkfinder::$panels = [];
    expect($validator->startErrors($this->contributes, $jobs, new Linkfinder))->toContain('It declares the editor panel “links” but has no EditorPanel for it.');

    Linkfinder::$panels = ['links' => new LinksPanel, 'other' => new LinksPanel];
    expect($validator->startErrors($this->contributes, $jobs, new Linkfinder))->toContain('It has an editor panel “other”, which isn’t declared.');
});

it('says in the consent what a panel may do, on the plugins screen and at the shell', function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('linkfinder');
    $line = 'Add a panel to the editor for Posts: “Link suggestions”, which can start its work on the item you’re editing and suggest changes you choose to use.';

    expect(PluginWords::editorPanel('Link suggestions', ['Posts']))->toBe($line);

    $admin = User::factory()->create()->assignRole('administrator');
    $row = collect($this->actingAs($admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->firstWhere('name', 'tests/linkfinder');

    expect(array_column($row['asks'], 'words'))->toContain($line);

    $this->artisan('teme:plugins:enable', ['name' => 'tests/linkfinder'])
        ->expectsOutputToContain($line)
        ->assertFailed();

    PluginFixtures::forget();
});

it('states in the schema the validator’s bounds, icons and patterns', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $panels = $schema['properties']['contributes']['properties']['editorPanels'];
    $panel = $panels['items']['properties'];
    $action = $panel['actions']['items']['properties'];
    $field = $action['fields']['items']['properties'];
    $between = fn (string $pattern): string => substr($pattern, 1, -2);

    expect($panels['maxItems'])->toBe(PanelValidator::MOST)
        ->and($panel['id']['pattern'])->toBe($between(PanelValidator::ID))
        ->and($panel['icon']['enum'])->toBe(PanelValidator::icons())
        ->and($panel['types']['items']['pattern'])->toBe($between(ContributionsValidator::TYPE_KEY))
        ->and($panel['actions']['maxItems'])->toBe(PanelValidator::MOST_ACTIONS)
        ->and($action['id']['pattern'])->toBe($between(PanelValidator::ID))
        ->and($action['fields']['maxItems'])->toBe(PanelValidator::MOST_FIELDS)
        ->and($field['key']['pattern'])->toBe($between(PanelValidator::FIELD_KEY))
        ->and($field['type']['enum'])->toBe(array_keys(PanelValidator::FIELD_TYPES))
        ->and(array_diff(array_keys($this->contributes['editorPanels'][0]), array_keys($panel)))->toBe([])
        ->and(array_diff(array_keys($this->contributes['editorPanels'][0]['actions'][1]), array_keys($action)))->toBe([]);
});
