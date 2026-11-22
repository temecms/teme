<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Operations\Input\UpdateFieldInput;
use App\Domain\Fields\Operations\UpdateField;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\ApplyPluginTypes;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\ApplyPluginTypesInput;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\Operations\MigratePlugin;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Operations\DeleteTaxonomy;
use App\Domain\Types\Operations\DeleteType;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\DeleteTaxonomyInput;
use App\Domain\Types\Operations\Input\DeleteTypeInput;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Domain\Types\TaxonomyRegistry;
use App\Domain\Types\TypeDefinition;
use App\Domain\Types\TypesFile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    PluginFixtures::use('recipes');
    app()->instance(Elevation::class, new OperatorElevation);

    // The fixture's types file, decoded, for a copy to change.
    $this->file = fn (): array => json_decode((string) file_get_contents(PluginFixtures::path('recipes').'/types/teme-types.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->enable = function (?string $folder = null): string {
        $folder ??= PluginFixtures::path('recipes');
        $manifest = json_decode((string) file_get_contents($folder.'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        // A request in between leaves the scoped binding to the session's.
        app()->instance(Elevation::class, new OperatorElevation);

        return app(EnablePlugin::class)->execute(new EnablePluginInput(
            name: 'tests/recipes',
            version: $manifest['version'],
            capabilities: [],
            hosts: [],
            hooks: [],
            actorId: (string) $this->admin->id,
        ));
    };
    $this->disable = function (): string {
        app()->instance(Elevation::class, new OperatorElevation);

        return app(DisablePlugin::class)->execute(new DisablePluginInput('tests/recipes', (string) $this->admin->id));
    };
    // A copy of the fixture whose types file is $file, at $version.
    $this->copy = function (array $file, string $version = '1.0.0', array $manifest = []): string {
        $copy = PluginFixtures::copy('recipes', ['version' => $version, ...$manifest], files: ['types/teme-types.json' => json_encode($file, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)]);
        PluginFixtures::use($copy);

        return $copy;
    };
    // A recipe written in the admin and published, holding $fields.
    $this->recipe = function (string $title, array $fields = []): Content {
        $this->actingAs($this->admin)->post('/admin/recipes', ['title' => $title])->assertRedirect();
        $item = Content::query()->where('type', 'recipe')->latest('id')->firstOrFail();

        if ($fields !== []) {
            DB::table('content_revisions')->where('id', $item->working_revision_id)->update(['fields' => json_encode($fields, JSON_THROW_ON_ERROR)]);
        }

        listsPublish($item, $this->admin);

        return $item->refresh();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('brings the plugin’s type, taxonomy and fields when it is enabled, into the menu, the editor and the site', function (): void {
    ($this->enable)();

    expect(app(ContentTypeRegistry::class)->has('recipe'))->toBeTrue()
        ->and(app(TaxonomyRegistry::class)->has('cuisine'))->toBeTrue()
        ->and(array_map(fn ($field): string => $field->handle, app(FieldRegistry::class)->stored('recipe')))->toBe(['serves', 'cook_time'])
        ->and(DB::table('content_type_definitions')->where('key', 'recipe')->value('plugin'))->toBe('tests/recipes')
        ->and(DB::table('taxonomy_definitions')->where('key', 'cuisine')->value('plugin'))->toBe('tests/recipes')
        ->and(DB::table('field_definitions')->where('plugin', 'tests/recipes')->count())->toBe(2);

    $recipe = ($this->recipe)('Pancakes');

    $this->actingAs($this->admin)->get('/admin/recipes')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('content/index')->where('labels.plural', 'Recipes'));
    $this->actingAs($this->admin)->get(route('content.edit', $recipe->id))->assertOk();
    addrGet('/recipes/pancakes/')->assertOk()->assertSee('Pancakes');
});

it('hides the plugin’s types when it is disabled, answering 404, and keeps every item and value', function (): void {
    ($this->enable)();
    $recipe = ($this->recipe)('Pancakes', ['01JQ0000000000000000000SRV' => 4]);

    ($this->disable)();

    expect(app(ContentTypeRegistry::class)->has('recipe'))->toBeFalse()
        ->and(app(TaxonomyRegistry::class)->has('cuisine'))->toBeFalse()
        ->and(app(FieldRegistry::class)->stored('recipe'))->toBe([])
        ->and(Content::query()->whereKey($recipe->id)->exists())->toBeTrue()
        ->and(DB::table('content_revisions')->where('id', $recipe->working_revision_id)->value('fields'))->toContain('"01JQ0000000000000000000SRV": 4')
        ->and(DB::table('content_type_definitions')->where('key', 'recipe')->exists())->toBeTrue();

    $this->actingAs($this->admin)->get('/admin/recipes')->assertNotFound();
    addrGet('/recipes/pancakes/')->assertNotFound();
});

it('brings everything back as it was when the plugin is enabled again', function (): void {
    ($this->enable)();
    $recipe = ($this->recipe)('Pancakes', ['01JQ0000000000000000000SRV' => 4]);
    ($this->disable)();

    ($this->enable)();

    expect(app(ContentTypeRegistry::class)->has('recipe'))->toBeTrue()
        ->and(app(FieldRegistry::class)->find('01JQ0000000000000000000SRV')?->handle)->toBe('serves')
        ->and(PluginRecord::query()->where('name', 'tests/recipes')->sole()->types['report']['added'])->toBe([]);

    addrGet('/recipes/pancakes/')->assertOk()->assertSee('Pancakes');
    $this->actingAs($this->admin)->get(route('content.edit', $recipe->id))->assertOk();
});

it('takes over a type, taxonomy and field made here with the same keys, keeping every item and value, and says what changed', function (): void {
    $file = ($this->file)();
    scElevated(fn () => app(ImportTypes::class)->execute(new ImportTypesInput([
        ...$file,
        'types' => [[...$file['types'][0], 'plural' => 'Recipe cards']],
        'fields' => [$file['fields'][0]],
    ])));
    $recipe = ($this->recipe)('Pancakes', ['01JQ0000000000000000000SRV' => 4]);

    ($this->enable)();

    $record = PluginRecord::query()->where('name', 'tests/recipes')->sole();

    expect(app(ContentTypeRegistry::class)->get('recipe')->plural)->toBe('Recipes')
        ->and(DB::table('content_type_definitions')->where('key', 'recipe')->value('plugin'))->toBe('tests/recipes')
        ->and($record->types['report']['adopted'])->toEqual(['types' => ['recipe'], 'taxonomies' => ['cuisine'], 'fields' => ['recipe.serves']])
        ->and($record->types['report']['updated'])->toBe(['recipe'])
        ->and($record->types['report']['fieldsAdded'])->toBe(['recipe.cook_time'])
        ->and(Content::query()->where('type', 'recipe')->pluck('id')->all())->toBe([$recipe->id])
        ->and(DB::table('content_revisions')->where('id', $recipe->working_revision_id)->value('fields'))->toContain('"01JQ0000000000000000000SRV": 4')
        ->and(app(PluginList::class)->typesReport('tests/recipes'))->toBe('Added 1 field. Took over Recipes, Cuisines and 1 field, keeping their items.');
});

it('takes over a type stored with an admin screen’s name and fields made here under other keys, renaming it to the file’s and keeping the values', function (): void {
    $file = ($this->file)();
    scElevated(fn () => app(ImportTypes::class)->execute(new ImportTypesInput([
        ...$file,
        'fields' => [[...$file['fields'][0], 'key' => '01JQ0000000000000000000XYZ']],
    ])));
    // As an older version stored it, before such names were refused.
    DB::table('content_type_definitions')->where('key', 'recipe')->update(['definition' => DB::raw("jsonb_set(definition, '{plural}', '\"Plugins\"')")]);
    $recipe = ($this->recipe)('Pancakes', ['01JQ0000000000000000000XYZ' => 4]);
    ($this->copy)([...$file, 'fields' => [...$file['fields'], ['key' => '01JQ0000000000000000000KCK', 'type' => 'page', 'handle' => 'kicker', 'label' => 'Kicker', 'fieldType' => 'text']]]);

    ($this->enable)();

    expect(app(ContentTypeRegistry::class)->get('recipe')->plural)->toBe('Recipes')
        ->and(app(FieldRegistry::class)->find('01JQ0000000000000000000XYZ')?->handle)->toBe('serves')
        ->and(DB::table('field_definitions')->where('key', '01JQ0000000000000000000SRV')->exists())->toBeFalse()
        ->and(DB::table('field_definitions')->where('key', '01JQ0000000000000000000XYZ')->value('plugin'))->toBe('tests/recipes')
        ->and(DB::table('field_definitions')->where('key', '01JQ0000000000000000000KCK')->value('plugin'))->toBe('tests/recipes')
        ->and(app(FieldRegistry::class)->find('01JQ0000000000000000000KCK')?->type)->not->toBeNull()
        ->and(DB::table('content_revisions')->where('id', $recipe->working_revision_id)->value('fields'))->toContain('"01JQ0000000000000000000XYZ": 4');

    // Enabled again, the file's own key still finds the field it took over.
    ($this->disable)();
    ($this->enable)();

    expect(app(FieldRegistry::class)->find('01JQ0000000000000000000XYZ')?->handle)->toBe('serves')
        ->and(DB::table('field_definitions')->where('content_type', 'recipe')->where('handle', 'serves')->count())->toBe(1);
});

it('refuses to enable, writing nothing, when a field it takes over holds values of another kind', function (): void {
    $file = ($this->file)();
    scElevated(fn () => app(ImportTypes::class)->execute(new ImportTypesInput([
        ...$file,
        'fields' => [[...$file['fields'][0], 'fieldType' => 'text']],
    ])));
    ($this->recipe)('Pancakes', ['01JQ0000000000000000000SRV' => 'four']);

    expect(fn () => ($this->enable)())->toThrow(function (ValidationException $refused): void {
        expect($refused->errors()['plugin'])->toContain('Its content types can’t be added: The field serves on Recipes is text here and holds values in 1 items, but the plugin makes it number, which would read those values as another kind.');
    });

    expect(PluginRecord::query()->count())->toBe(0)
        ->and(DB::table('content_type_definitions')->whereNotNull('plugin')->count())->toBe(0)
        ->and(app(FieldRegistry::class)->find('01JQ0000000000000000000SRV')?->type->name())->toBe('text');
});

it('lets the file change a field’s kind while no item holds a value in it', function (): void {
    $file = ($this->file)();
    scElevated(fn () => app(ImportTypes::class)->execute(new ImportTypesInput([...$file, 'fields' => [[...$file['fields'][0], 'fieldType' => 'text']]])));

    ($this->enable)();

    expect(app(FieldRegistry::class)->find('01JQ0000000000000000000SRV')?->type->name())->toBe('number');
});

it('applies an update’s file: adds and changes what it says, and gives the site what it no longer has, saying so', function (): void {
    ($this->enable)();
    $recipe = ($this->recipe)('Pancakes', ['01JQ0000000000000000000TME' => '20 minutes']);
    $file = ($this->file)();
    ($this->copy)([
        ...$file,
        'types' => [[...$file['types'][0], 'singular' => 'Dish', 'plural' => 'Dishes']],
        'fields' => [$file['fields'][0], ['key' => '01JQ0000000000000000000NTS', 'type' => 'recipe', 'handle' => 'notes', 'label' => 'Notes', 'fieldType' => 'text']],
    ], '1.1.0');

    $found = app(PluginRegistry::class)->find('tests/recipes');
    expect(app(PluginList::class)->all()[0]->state->value)->toBe('waiting');

    app()->instance(Elevation::class, new OperatorElevation);
    $ran = app(MigratePlugin::class)->execute(new MigratePluginInput('tests/recipes', (string) $this->admin->id));

    expect($ran)->toBe(['types/teme-types.json'])
        ->and(app(ContentTypeRegistry::class)->get('recipe')->plural)->toBe('Dishes')
        ->and(array_map(fn ($field): string => $field->handle, app(FieldRegistry::class)->stored('recipe')))->toBe(['serves', 'notes', 'cook_time'])
        ->and(DB::table('field_definitions')->where('key', '01JQ0000000000000000000TME')->value('plugin'))->toBeNull()
        ->and(DB::table('content_revisions')->where('id', $recipe->working_revision_id)->value('fields'))->toContain('20 minutes')
        ->and(app(PluginList::class)->typesReport('tests/recipes'))->toBe('Added 1 field. Changed Dishes to match its file. Its file no longer has 1 field, so they stay as this site’s own, with their items.')
        ->and($found)->not->toBeNull();
});

it('refuses changes in Settings › Types to a plugin’s type, taxonomy and fields', function (): void {
    ($this->enable)();
    $type = TypeDefinition::fromArray([...($this->file)()['types'][0], 'plural' => 'Recipe cards']);
    $said = 'This comes from the plugin Recipe box, so only its updates change it.';

    expect(fn () => app(UpdateType::class)->execute(new UpdateTypeInput($type, (string) $this->admin->id)))->toThrow(ValidationException::class, $said)
        ->and(fn () => app(DeleteType::class)->execute(new DeleteTypeInput('recipe', (string) $this->admin->id)))->toThrow(ValidationException::class, $said)
        ->and(fn () => app(DeleteTaxonomy::class)->execute(new DeleteTaxonomyInput('cuisine', (string) $this->admin->id)))->toThrow(ValidationException::class, $said)
        ->and(fn () => app(UpdateField::class)->execute(new UpdateFieldInput('recipe', '01JQ0000000000000000000SRV', FieldDefinition::fromArray([...($this->file)()['fields'][0], 'label' => 'People']), (string) $this->admin->id)))->toThrow(ValidationException::class, $said);

    expect(app(ContentTypeRegistry::class)->get('recipe')->plural)->toBe('Recipes');

    $this->actingAs($this->admin)->get(route('types.show', ['key' => 'recipe']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'plugin')->where('source', 'plugin')->where('plugin', 'Recipe box')->where('pluginEnabled', true));
    $this->actingAs($this->admin)->get(route('types.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('types', fn ($types): bool => collect($types)->firstWhere('key', 'recipe')['source'] === 'plugin'
            && collect($types)->firstWhere('key', 'recipe')['plugin'] === 'Recipe box'));
});

it('refuses an import that would change a plugin’s type, and leaves the plugin’s out of the export', function (): void {
    ($this->enable)();
    $file = ($this->file)();

    expect(fn () => scElevated(fn () => app(ImportTypes::class)->execute(new ImportTypesInput([...$file, 'types' => [[...$file['types'][0], 'plural' => 'Recipe cards']]]))))
        ->toThrow(ValidationException::class, 'Recipe cards comes from the plugin Recipe box, so only that plugin changes it.');

    expect(TypesFile::export()['types'])->toBe([])
        ->and(TypesFile::export()['fields'])->toBe([]);
});

it('writes nothing when someone without plugins.manage applies a plugin’s types', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(ApplyPluginTypes::class)->execute(new ApplyPluginTypesInput('tests/recipes', (string) $editor->id)))->toThrow(AuthorizationException::class);
    expect(DB::table('content_type_definitions')->count())->toBe(0)
        ->and(DB::table('taxonomy_definitions')->count())->toBe(0)
        ->and(DB::table('field_definitions')->count())->toBe(0);
});

it('refuses a manifest whose types file is missing, not JSON or not a teme-types file, saying why', function (string $contents, string $problem): void {
    $copy = PluginFixtures::copy('recipes', files: $contents === '' ? [] : ['types/teme-types.json' => $contents]);

    if ($contents === '') {
        unlink($copy.'/types/teme-types.json');
    }

    PluginFixtures::use($copy);

    expect(app(PluginRegistry::class)->find('tests/recipes')?->problems)->toContain($problem);
    expect(fn () => ($this->enable)($copy))->toThrow(ValidationException::class, $problem);
})->with([
    'missing' => ['', 'contributes.types names types/teme-types.json, which the plugin doesn’t have.'],
    'not JSON' => ['{"format": ', 'types/teme-types.json is not valid JSON: Syntax error.'],
    'not a types file' => ['{"format": "teme-fields", "version": 1}', 'types/teme-types.json isn’t a teme-types file: it needs "format": "teme-types" and "version": 1, as Settings › Types exports.'],
]);

it('refuses a manifest that points its types outside the plugin', function (): void {
    PluginFixtures::use(PluginFixtures::copy('recipes', ['contributes' => ['types' => '../hello/teme-plugin.json']]));

    expect(app(PluginRegistry::class)->find('tests/recipes')?->problems)->toContain('contributes.types must be the path of a teme-types file inside the plugin, such as types/teme-types.json.');
});

it('refuses a file whose type is invalid on this site, with the reason in words, and writes nothing', function (): void {
    $file = ($this->file)();
    $copy = ($this->copy)([...$file, 'types' => [[...$file['types'][0], 'segment' => 'media']]]);

    expect(fn () => ($this->enable)($copy))->toThrow(ValidationException::class, 'Its content types can’t be added: /admin/media is one of the admin’s own screens.');
    expect(PluginRecord::query()->count())->toBe(0)
        ->and(DB::table('content_type_definitions')->count())->toBe(0);
});

it('refuses a file whose type or taxonomy takes an admin screen’s name, saying which and what to call it', function (): void {
    $file = ($this->file)();
    $copy = ($this->copy)([...$file, 'types' => [[...$file['types'][0], 'plural' => 'navigation']]]);

    expect(fn () => ($this->enable)($copy))->toThrow(ValidationException::class, 'Its content types can’t be added: The admin menu already has Navigation. Give the type another plural name, such as “Recipe directory”.');

    $copy = ($this->copy)([...$file, 'taxonomies' => [[...$file['taxonomies'][0], 'plural' => 'People']]]);

    expect(fn () => ($this->enable)($copy))->toThrow(ValidationException::class, 'Its content types can’t be added: The admin menu already has People. Give the taxonomy another plural name, such as “Cuisine directory”.');
    expect(PluginRecord::query()->count())->toBe(0)
        ->and(DB::table('content_type_definitions')->count())->toBe(0);
});
