<?php

declare(strict_types=1);

use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\ImportNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Menus\Operations\Input\ImportNavigationInput;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    siteSetLanguage($this->admin, 'en-GB');
    Languages::set(['fr', 'es'], hidden: ['es']);
});

function mplBind(User $actor, string $slot, ?string $menu, ?string $language = null): ?string
{
    return app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu, (string) $actor->id, $language));
}

it('makes a menu in another of the site\'s languages, and the default\'s as null', function (): void {
    $french = app(CreateNavigation::class)->execute(new CreateNavigationInput('Principal', (string) $this->editor->id, 'fr'));
    $english = app(CreateNavigation::class)->execute(new CreateNavigationInput('Main', (string) $this->editor->id, 'en-GB'));
    $plain = navCreate($this->editor, 'Footer');

    expect(DB::table('navigations')->where('id', $french->id)->value('language'))->toBe('fr')
        ->and(DB::table('navigations')->where('id', $english->id)->value('language'))->toBeNull()
        ->and(DB::table('navigations')->where('id', $plain->id)->value('language'))->toBeNull();
});

it('refuses a menu in a language the site doesn\'t have, and writes nothing', function (): void {
    expect(fn () => app(CreateNavigation::class)->execute(new CreateNavigationInput('Principale', (string) $this->editor->id, 'it')))
        ->toThrow(ValidationException::class, 'Choose one of the site’s languages.');

    expect(Navigation::query()->count())->toBe(0);
});

it('holds a stored language to a tag\'s form', function (): void {
    $menu = navCreate($this->editor);

    expect(fn () => DB::table('navigations')->where('id', $menu->id)->update(['language' => 'French']))
        ->toThrow(QueryException::class, 'navigations_language_check');
});

it('imports a menu in its language', function (): void {
    $result = app(ImportNavigation::class)->execute(new ImportNavigationInput(null, 'Principal', [['kind' => 'home']], null, (string) $this->admin->id, 'fr'));

    expect(Navigation::query()->find($result['navigationId'])->language)->toBe('fr');
});

it('binds a menu to a slot for French only, leaving the default language\'s as it was', function (): void {
    $main = navCreate($this->editor, 'Main');
    $principal = navCreate($this->editor, 'Principal');
    mplBind($this->editor, 'primary', $main->id);

    expect(mplBind($this->editor, 'primary', $principal->id, 'fr'))->toBe($principal->id);

    expect(app(SettingsReader::class)->string('theme.default', ThemeSlotSettings::settingKey('primary')))->toBe($main->id)
        ->and(SettingsGroupRecord::query()->find('theme.default')->translations)->toEqual(['fr' => ['nav_primary' => $principal->id]]);

    // None for French shows the default language's menu there again.
    mplBind($this->editor, 'primary', null, 'fr');

    expect(SettingsGroupRecord::query()->find('theme.default')->translations)->toBeNull();
});

it('refuses to bind a menu for a language that isn\'t one of the other languages', function (string $language): void {
    $menu = navCreate($this->editor);

    expect(fn () => mplBind($this->editor, 'primary', $menu->id, $language))->toThrow(ValidationException::class);
    expect(SettingsGroupRecord::query()->find('theme.default'))->toBeNull();
})->with(['the default' => 'en-GB', 'not added' => 'it']);

it('writes nothing when someone without navigation.manage binds a slot for a language', function (): void {
    $menu = navCreate($this->editor);
    $before = ['settings' => DB::table('settings')->orderBy('group_key')->get()->all(), 'changes' => DB::table('settings_changes')->count()];

    expect(fn () => mplBind($this->author, 'primary', $menu->id, 'fr'))->toThrow(AuthorizationException::class);

    expect(['settings' => DB::table('settings')->orderBy('group_key')->get()->all(), 'changes' => DB::table('settings_changes')->count()])->toEqual($before);
});

it('saves a language switcher with the options a theme reads', function (): void {
    $menu = navCreate($this->editor);
    navSave($this->editor, $menu, [['kind' => 'home'], ['kind' => 'languages', 'options' => ['dropdown' => true, 'show_names' => false]], ['kind' => 'languages']]);

    $switchers = NavigationItem::query()->where('navigation_id', $menu->id)->where('kind', 'languages')->orderBy('position')->get();

    expect($switchers)->toHaveCount(2)
        ->and($switchers[0]->options)->toBe(['dropdown' => true, 'show_names' => false])
        ->and($switchers[0]->target_id)->toBeNull()
        ->and($switchers[0]->url)->toBeNull()
        ->and($switchers[1]->options)->toBe([])
        ->and(NavigationItem::query()->where('kind', 'home')->value('options'))->toBeNull();
});

it('refuses a switcher with an unknown option or with items under it, naming the item', function (array $tree, string $message): void {
    $menu = navCreate($this->editor);

    expect(fn () => navSave($this->editor, $menu, $tree))->toThrow(ValidationException::class, $message);
    expect(NavigationItem::query()->count())->toBe(0);
})->with([
    'an unknown option' => [[['kind' => 'home'], ['kind' => 'languages', 'options' => ['flags' => true]]], 'Item 2: A language switcher has no option “flags”.'],
    'an option not true or false' => [[['kind' => 'languages', 'options' => ['dropdown' => 'yes']]], 'Item 1: Set the language switcher’s “dropdown” to true or false.'],
    'items under it' => [[['kind' => 'languages', 'children' => [['kind' => 'home']]]], 'Item 1: Nothing can go under a language switcher.'],
]);
