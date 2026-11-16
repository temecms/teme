<?php

declare(strict_types=1);

use App\Domain\Plugins\Area\PluginPermissions;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\Operations\RemovePluginData;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PluginFixtures::use('placard');

    $this->enable = function (): void {
        $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    };

    $this->holders = fn (string $permission): array => DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->where('permissions.name', $permission)
        ->orderBy('roles.name')
        ->pluck('roles.name')
        ->all();

    $this->errors = fn (array $contributes): array => app(ContributionsValidator::class)->errors($contributes, '1.0.0', ['jobs']);
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('makes the plugin’s permission when it is enabled, held by administrators and the roles it names', function (): void {
    ($this->enable)();

    expect(($this->holders)('plugin.placard.manage'))->toBe(['administrator', 'editor'])
        ->and(User::factory()->create()->assignRole('editor')->can('plugin.placard.manage'))->toBeTrue()
        ->and(User::factory()->create()->assignRole('author')->can('plugin.placard.manage'))->toBeFalse();
});

it('never gives back a role someone took away when the plugin is enabled again', function (): void {
    ($this->enable)();
    Role::findByName('editor')->revokePermissionTo('plugin.placard.manage');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/placard', null));

    ($this->enable)();

    expect(($this->holders)('plugin.placard.manage'))->toBe(['administrator']);
});

it('gives a role an update adds once it is agreed to, and still never gives back one taken away', function (): void {
    ($this->enable)();
    Role::findByName('editor')->revokePermissionTo('plugin.placard.manage');

    $contributes = PluginFixtures::manifest('placard')['contributes'];
    $contributes['permissions'][0]['roles'] = ['editor', 'author'];
    PluginFixtures::use(PluginFixtures::copy('placard', ['version' => '1.1.0', 'contributes' => $contributes]));
    ($this->enable)();

    expect(($this->holders)('plugin.placard.manage'))->toBe(['administrator', 'author']);
});

it('says in the consent who is given each permission in words that translate', function (): void {
    app('translator')->addLines(['*.authors' => 'autores', '*.editors' => 'editores', '*.:first and :second' => ':first y :second', '*.Lets :roles :label' => 'Permite a :roles :label'], 'es');
    app()->setLocale('es');
    $declared = PluginFixtures::manifest('placard');
    $declared['contributes']['permissions'][0]['roles'] = ['editor', 'author'];

    expect(PluginPermissions::consentWords(new Manifest($declared)))->toBe(['Permite a autores y editores manage ads']);
});

it('keeps the permission while the plugin is disabled, and removing its data deletes it', function (): void {
    ($this->enable)();
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/placard', null));

    expect(DB::table('permissions')->where('name', 'plugin.placard.manage')->exists())->toBeTrue();

    app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/placard', null));

    expect(DB::table('permissions')->where('name', 'like', 'plugin.placard.%')->exists())->toBeFalse();
});

it('lists each permission in the consent, and an update that adds a permission or a role waits for consent', function (): void {
    ($this->enable)();
    $consent = PluginRecord::query()->where('name', 'tests/placard')->sole()->consent;
    $declared = PluginFixtures::manifest('placard');
    $with = function (array $permissions) use ($declared): Manifest {
        $declared['contributes']['permissions'] = $permissions;

        return new Manifest($declared);
    };
    $manage = $declared['contributes']['permissions'][0];

    expect(Consent::covers($consent, new Manifest($declared)))->toBeTrue()
        ->and(Consent::covers($consent, $with([[...$manage, 'roles' => []]])))->toBeTrue()
        ->and(Consent::covers($consent, $with([[...$manage, 'roles' => ['editor', 'author']]])))->toBeFalse()
        ->and(Consent::covers($consent, $with([$manage, ['id' => 'report', 'label' => 'See reports', 'roles' => []]])))->toBeFalse()
        ->and(Consent::added($consent, $with([[...$manage, 'roles' => ['editor', 'author']]]))['permissions'] ?? null)->toBe(['manage:author']);
});

it('refuses a manifest whose permissions are out of bounds, in one plain sentence each', function (array $contributes, string $error): void {
    expect(($this->errors)($contributes))->toContain($error);
})->with([
    'more than three' => [['permissions' => array_map(fn (int $n): array => ['id' => "p{$n}", 'label' => "P{$n}", 'roles' => []], range(1, 4))], 'A plugin can declare at most 3 permissions.'],
    'a bad id' => [['permissions' => [['id' => 'Manage', 'label' => 'Manage', 'roles' => []]]], 'A permission’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.'],
    'an unknown role' => [['permissions' => [['id' => 'manage', 'label' => 'Manage', 'roles' => ['subscriber']]]], 'A permission’s roles must be editor, author or contributor; administrators always hold it.'],
    'an area naming no declared permission' => fn (): array => [['permissions' => [['id' => 'manage', 'label' => 'Manage', 'roles' => []]], 'area' => [...PluginFixtures::manifest('placard')['contributes']['area'], 'permission' => 'report']], 'An area must name one of the permissions the plugin declares.'],
]);
