<?php

declare(strict_types=1);

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);
    $this->errors = fn (array $manifest): array => app(ManifestValidator::class)->errors($manifest, PluginFixtures::path('importer'), PluginFixtures::composer('importer'));
    $this->imports = fn (array $imports): array => [...PluginFixtures::manifest('importer'), 'imports' => [...PluginFixtures::manifest('importer')['imports'], ...$imports]];
});

afterEach(fn () => PluginFixtures::forget());

it('accepts the importer fixture, whose imports block declares what it brings', function (): void {
    expect(($this->errors)(PluginFixtures::manifest('importer')))->toBe([])
        ->and((new Manifest(PluginFixtures::manifest('importer')))->imports())->toBe([
            'importer' => 'Tests\\Fixtures\\Plugins\\Importer\\Importer',
            'options' => ['importer_mode'],
            'postTypes' => ['ap_actor' => ['_importer_inbox', '_importer_following']],
            'userMeta' => ['importer_also_known_as'],
            'termMeta' => [],
        ]);
});

it('refuses each broken imports block with its sentence', function (Closure $break, string $sentence): void {
    expect(($this->errors)($break($this)))->toContain($sentence);
})->with([
    'an unknown key' => [fn ($test): array => ($test->imports)(['tables' => ['wp_x']]), 'imports may hold only importer, options, postTypes, userMeta and termMeta: "tables" isn’t one of them.'],
    'not an object' => [fn ($test): array => [...PluginFixtures::manifest('importer'), 'imports' => ['importer_mode']], 'imports must be an object: what the plugin brings across from WordPress.'],
    'no importer' => [fn ($test): array => ($test->imports)(['importer' => '']), 'imports.importer must name the plugin’s importer class.'],
    'a name of the wrong shape' => [fn ($test): array => ($test->imports)(['options' => ['importer mode']]), 'imports.options "importer mode" isn’t the shape of a WordPress option or meta key.'],
    'a list that isn’t one' => [fn ($test): array => ($test->imports)(['userMeta' => 'importer_also_known_as']), 'imports.userMeta must be a list of names.'],
    'post types that aren’t an object' => [fn ($test): array => ($test->imports)(['postTypes' => ['ap_actor']]), 'imports.postTypes must be an object of post types, each with a list of its meta keys.'],
    'a post type of the wrong shape' => [fn ($test): array => ($test->imports)(['postTypes' => ['ap actor' => []]]), 'imports.postTypes "ap actor" isn’t the shape of a WordPress post type.'],
    'user_pass' => [fn ($test): array => ($test->imports)(['userMeta' => ['user_pass']]), 'imports.userMeta names user_pass, which Teme never brings across from WordPress.'],
    'session_tokens' => [fn ($test): array => ($test->imports)(['userMeta' => ['session_tokens']]), 'imports.userMeta names session_tokens, which Teme never brings across from WordPress.'],
    '_application_passwords' => [fn ($test): array => ($test->imports)(['postTypes' => ['ap_actor' => ['_application_passwords']]]), 'imports.postTypes.ap_actor names _application_passwords, which Teme never brings across from WordPress.'],
    'wp_user_roles' => [fn ($test): array => ($test->imports)(['options' => ['wp_user_roles']]), 'imports.options names wp_user_roles, which Teme never brings across from WordPress.'],
    'user_pass in capitals' => [fn ($test): array => ($test->imports)(['userMeta' => ['User_Pass']]), 'imports.userMeta names User_Pass, which Teme never brings across from WordPress.'],
    'wp_user_roles in capitals' => [fn ($test): array => ($test->imports)(['options' => ['WP_USER_ROLES']]), 'imports.options names WP_USER_ROLES, which Teme never brings across from WordPress.'],
    'another table prefix’s user_roles' => [fn ($test): array => ($test->imports)(['options' => ['site1_user_roles']]), 'imports.options names site1_user_roles, which Teme never brings across from WordPress.'],
    'mailserver_pass' => [fn ($test): array => ($test->imports)(['options' => ['mailserver_pass']]), 'imports.options names mailserver_pass, which Teme never brings across from WordPress.'],
    'wp_capabilities' => [fn ($test): array => ($test->imports)(['userMeta' => ['wp_capabilities']]), 'imports.userMeta names wp_capabilities, which Teme never brings across from WordPress.'],
    'another table prefix’s capabilities in capitals' => [fn ($test): array => ($test->imports)(['userMeta' => ['WP_2_Capabilities']]), 'imports.userMeta names WP_2_Capabilities, which Teme never brings across from WordPress.'],
    'more than fifty names' => [fn ($test): array => ($test->imports)(['options' => array_map(fn (int $i): string => "importer_{$i}", range(1, 48))]), 'imports declares 52 names; declare only what the plugin needs, at most 50.'],
    'without the capability' => [fn ($test): array => [...PluginFixtures::manifest('importer'), 'capabilities' => []], 'It declares imports but doesn’t ask for import.wordpress.'],
    'the capability without imports' => [fn ($test): array => array_diff_key(PluginFixtures::manifest('importer'), ['imports' => true]), 'It asks for import.wordpress but declares nothing to import.'],
]);

it('shows the declared names on the consent screen', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    PluginFixtures::use('importer');

    $plugins = collect($this->actingAs($admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name');

    expect($plugins['tests/importer']['asks'])->toBe([[
        'words' => 'Read from the WordPress site you import: the options importer_mode; the post type ap_actor with its meta _importer_inbox, _importer_following; people’s meta importer_also_known_as',
        'new' => false,
    ]]);
});
