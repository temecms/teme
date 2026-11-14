<?php

declare(strict_types=1);

use App\Domain\Cards\CardRegistry;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Guides\EditorGuide;
use Tests\Fixtures\Guides\GuideFile;

uses(RefreshDatabase::class);

/*
 * What the editors' guide must cover because the admin has it, and what it
 * says about roles, held to the code that makes them true.
 */

it('says who can do what exactly as the roles are seeded', function (): void {
    preg_match_all('/^\|\s*(.+?)\s*\|\s*(Yes|No)\s*\|\s*(Yes|No)\s*\|\s*(Yes|No)\s*\|\s*(Yes|No)\s*\|\s*$/m', EditorGuide::page('README.md')->body(), $rows, PREG_SET_ORDER);

    $written = [];

    foreach ($rows as $row) {
        $written[$row[1]] = array_slice($row, 2);
    }

    $seeded = [];

    foreach (EditorGuide::ABILITIES as $label => $permission) {
        $seeded[$label] = array_map(fn (string $role): string => in_array($permission, RoleSeeder::ROLES[$role], true) ? 'Yes' : 'No', EditorGuide::ROLES);
    }

    // toBe() on arrays also compares their order: the rows stay in EditorGuide::ABILITIES' order.
    expect($written)->toBe($seeded);
});

it('describes every Settings page an administrator can open, under its own name', function (): void {
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create()->assignRole('administrator');
    $labels = array_column(app(SettingsNavigation::class)->forUser($administrator->id), 'label');

    expect($labels)->not->toBe([])
        ->and(array_values(array_diff($labels, EditorGuide::page('settings.md')->headingsAt(3))))->toBe([]);
});

it('names only settings that exist', function (GuideFile $page): void {
    $groups = app(SettingsRegistry::class)->all();
    $keys = [];

    foreach ($groups as $group) {
        foreach ($group->settings as $setting) {
            $keys["{$group->key}.{$setting->key}"] = true;
        }
    }

    $unknown = [];

    foreach ($page->codeSpans() as $span) {
        if (preg_match('/^([a-z][a-z_]*)\.([a-z][a-z0-9_]*)$/', $span, $match) === 1 && in_array($match[1], array_map(fn ($group): string => $group->key, $groups), true) && ! isset($keys[$span])) {
            $unknown[] = $span;
        }
    }

    expect($unknown)->toBe([]);
})->with(EditorGuide::pages());

it('names every card the editor can insert, by the name the editor shows', function (): void {
    $labels = array_map(fn ($card): string => $card->label(), app(CardRegistry::class)->all());

    expect($labels)->not->toBe([])
        ->and(array_values(array_diff($labels, EditorGuide::page('writing.md')->boldSpans())))->toBe([]);
});

it('describes every status banner once, under the name of the check that draws it', function (): void {
    $checks = array_map(fn (string $file): string => basename($file, '.php'), glob(GuideFile::root().'/app/Domain/Status/Checks/*.php') ?: []);
    preg_match_all('/<!-- banner: (\w+) -->/', EditorGuide::page('status-banners.md')->text(), $found);

    expect($checks)->not->toBe([])
        ->and($found[1])->toEqualCanonicalizing($checks);
});
