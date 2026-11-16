<?php

declare(strict_types=1);

use App\Domain\Navigation\Queries\AdminNavigation;
use App\Domain\Navigation\ToolsHub;
use App\Domain\Plugins\Columns\PluginColumns;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Plugins\Lists\PluginActions;
use App\Domain\Plugins\Lists\PluginLists;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginAreaScreen;
use App\Domain\Plugins\Queries\PluginRecordForm;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Settings\Queries\SettingsForm;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Workbench\Workbench;

uses(RefreshDatabase::class);

// The fixture's classes, loaded before a test resets them.
foreach ([...(glob(dirname(__DIR__, 3).'/Fixtures/plugins/workbench/src/*.php') ?: []), ...(glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [])] as $file) {
    require_once $file;
}

/*
 * A plugin's manifest words translate (docs/plans/plugin-sockets-2.md, C2):
 * looked up by their English text in that plugin's own lang/<locale>.json,
 * never in core's, and English when its file has no line. The admin has one
 * language today (I-162), so the person's language is set as a second one
 * will set it, and the screens' queries are read.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app()->instance(ToolLinks::class, new ToolLinks);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Workbench::reset();

    $manifest = PluginFixtures::manifest('workbench');
    $contributes = $manifest['contributes'];
    $contributes['settings'][] = ['key' => 'mode', 'type' => 'select', 'label' => 'Mode', 'description' => 'How the service answers.', 'options' => ['open' => 'Open', 'closed' => 'Closed'], 'default' => 'open'];
    $contributes['settings'][1]['unit'] = 'pings';
    $contributes['admin'] = [['surface' => 'tools', 'id' => 'service', 'label' => 'Service status', 'description' => 'Whether the service is up.', 'to' => 'settings']];

    PluginFixtures::use(PluginFixtures::copy('workbench', ['contributes' => $contributes], files: [
        'lang/es.json' => json_encode([
            'Workbench' => 'Banco de trabajo',
            'A plugin the tests enable, which writes its own settings, offers an action and a list, and runs a job on a schedule.' => 'Un plugin de las pruebas.',
            'Site key' => 'Clave del sitio',
            'The key the service checks.' => 'La clave que comprueba el servicio.',
            'Mode' => 'Modo',
            'Open' => 'Abierto',
            'pings' => 'envíos',
            'Service status' => 'Estado del servicio',
            'Whether the service is up.' => 'Si el servicio funciona.',
            'Send a test ping' => 'Enviar un ping de prueba',
            'Tells the service about the home page now.' => 'Avisa al servicio de la portada ahora.',
            'Recent pings' => 'Pings recientes',
            'Address' => 'Dirección',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]));

    $found = app(PluginRegistry::class)->find('tests/workbench')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/workbench', $found?->version() ?? '1.0.0', $found?->capabilities() ?? [], $found?->hosts() ?? [], [], (string) $this->admin->id));
    app(PluginLoader::class)->load();
    cmSettings(['site_key' => 'Site key'], 'plugin.workbench');
});

afterEach(function (): void {
    Workbench::reset();
    PluginFixtures::forget();
});

it('shows a Spanish-speaking administrator the plugin’s settings, Tools link, action and list in its Spanish words', function (): void {
    app()->setLocale('es');

    $form = app(SettingsForm::class)->find('plugin.workbench');
    $fields = collect($form['fields'])->keyBy('key');

    expect($form['group']['label'])->toBe('Banco de trabajo')
        ->and($fields['site_key']['label'])->toBe('Clave del sitio')
        ->and($fields['site_key']['description'])->toBe('La clave que comprueba el servicio.')
        ->and($fields['mode']['label'])->toBe('Modo')
        ->and($fields['mode']['options'])->toBe(['open' => 'Abierto', 'closed' => 'Closed'])
        ->and($fields['batch']['unit'])->toBe('envíos');

    $link = collect(app(ToolsHub::class)->pagesFor($this->admin))->firstWhere('key', 'plugin.workbench.service');
    expect($link['label'])->toBe('Estado del servicio')
        ->and($link['description'])->toBe('Si el servicio funciona.');

    expect(app(PluginActions::class)->forGroup('plugin.workbench'))->toBe([['id' => 'test-ping', 'label' => 'Enviar un ping de prueba', 'description' => 'Avisa al servicio de la portada ahora.']]);

    $list = app(PluginLists::class)->forGroup('plugin.workbench')[0];
    expect($list['label'])->toBe('Pings recientes')
        ->and(array_column($list['columns'], 'label'))->toBe(['Dirección', 'Sent', 'Answer']);

    expect(collect(app(SettingsNavigation::class)->forUser($this->admin->id))->firstWhere('key', 'plugin.workbench')['label'])->toBe('Banco de trabajo');

    $row = collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/workbench');
    expect($row['label'])->toBe('Banco de trabajo')
        ->and($row['description'])->toBe('Un plugin de las pruebas.')
        ->and($row['adds'])->toContain('Clave del sitio', 'Estado del servicio');
});

it('falls back to English for a word the plugin’s file lacks, and never takes core’s line for it', function (): void {
    app()->setLocale('es');
    $core = json_decode((string) file_get_contents(lang_path('es.json')), true);

    $fields = collect(app(SettingsForm::class)->find('plugin.workbench')['fields'])->keyBy('key');

    expect($core)->toHaveKey('Closed')
        ->and($fields['mode']['options']['closed'])->toBe('Closed')
        ->and($fields['token']['label'])->toBe('Token');
});

it('answers in English in a language the plugin has no file for', function (): void {
    app()->setLocale('fr');

    expect(app(SettingsForm::class)->find('plugin.workbench')['group']['label'])->toBe('Workbench');
});

it('never translates a value a person typed', function (): void {
    app()->setLocale('es');

    expect(app(SettingsForm::class)->find('plugin.workbench')['values']['site_key'])->toBe('Site key');
});

it('heads a plugin’s list columns in its Spanish words', function (): void {
    PluginFixtures::use(PluginFixtures::copy('columns', files: [
        'lang/es.json' => json_encode(['SEO score' => 'Puntuación SEO', 'Columns' => 'Columnas'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]));
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $manifest = app(PluginRegistry::class)->find('tests/columns')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/columns', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->setLocale('es');

    $columns = collect(app(PluginColumns::class)->for('post'))->keyBy('id');

    expect($columns['score']->label)->toBe('Puntuación SEO')
        ->and($columns['score']->plugin)->toBe('Columnas')
        ->and($columns['views']->label)->toBe('Views');
});

it('shows a plugin’s area in its Spanish words: its menu entry, its pages and a record’s form', function (): void {
    PlacardArea::reset();
    PluginFixtures::use(PluginFixtures::copy('placard', files: [
        'lang/es.json' => json_encode(['Placard' => 'Cartel', 'Overview' => 'Resumen', 'Ad' => 'Anuncio', 'Basics' => 'Lo básico', 'Name' => 'Nombre'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]));
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->setLocale('es');

    $entry = collect(app(AdminNavigation::class)->forUser($this->admin->id))->firstWhere('key', 'plugin.placard');
    $screen = app(PluginAreaScreen::class);
    $plugin = $screen->plugin('placard', $this->admin);
    $form = $plugin === null ? null : app(PluginRecordForm::class)->find($plugin, 'ad', null, (string) $this->admin->id);

    expect($entry['label'] ?? null)->toBe('Cartel')
        ->and(array_column($plugin === null ? [] : ($screen->frame($plugin, null)['tabs'] ?? []), 'label'))->toBe(['Resumen', 'Ads'])
        ->and($form['record']['label'] ?? null)->toBe('Anuncio')
        ->and($form['sections'][0]['label'] ?? null)->toBe('Lo básico')
        ->and($form['sections'][0]['fields'][0]['label'] ?? null)->toBe('Nombre');

    PlacardArea::reset();
});
