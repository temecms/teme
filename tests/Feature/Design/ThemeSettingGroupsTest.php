<?php

/**
 * Theme setting groups inside a fieldset (issue 685): a `group` label draws
 * a foldable section; settings without one stay at the top; order follows
 * the manifest.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Design\Queries\DesignScreen;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('accepts a group label and refuses an empty one', function (): void {
    $validator = app(ManifestValidator::class);
    $path = base_path('tests/Fixtures/themes/good');

    expect($validator->errors(designManifest([
        ['key' => 'show_rail', 'type' => 'boolean', 'label' => 'Show rail', 'fieldset' => 'site', 'default' => true, 'group' => 'Home'],
        ['key' => 'rail_count', 'type' => 'number', 'label' => 'Rail count', 'fieldset' => 'site', 'default' => 3, 'group' => 'Home'],
    ]), $path))->toBe([]);

    expect(implode("\n", $validator->errors(designManifest([
        ['key' => 'show_rail', 'type' => 'boolean', 'label' => 'Show rail', 'fieldset' => 'site', 'default' => true, 'group' => '  '],
    ]), $path)))->toContain('"show_rail" has an empty group');
});

it('puts screen data in group order, and leaves a fieldset with no groups unchanged', function (): void {
    $source = base_path('tests/Fixtures/themes/good');
    $parent = sys_get_temp_dir().'/teme-groups-'.bin2hex(random_bytes(4));
    $folder = $parent.'/grouped';
    File::copyDirectory($source, $folder);

    $manifest = designManifest([
        ['key' => 'site_name', 'type' => 'text', 'label' => 'Site name', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'show_rail', 'type' => 'boolean', 'label' => 'Show rail', 'fieldset' => 'site', 'default' => true, 'group' => 'Home'],
        ['key' => 'rail_count', 'type' => 'number', 'label' => 'Rail count', 'fieldset' => 'site', 'default' => 3, 'group' => 'Home'],
        ['key' => 'share_whatsapp', 'type' => 'boolean', 'label' => 'WhatsApp', 'fieldset' => 'site', 'default' => false, 'group' => 'Share'],
        ['key' => 'feed_layout', 'type' => 'select', 'label' => 'Feed layout', 'fieldset' => 'latest', 'options' => ['list' => 'List', 'grid' => 'Grid'], 'default' => 'list'],
    ]);
    $manifest['code'] = 'grouped';
    $manifest['name'] = 'Grouped';
    file_put_contents($folder.'/teme-theme.json', (string) json_encode($manifest));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('grouped');

    $fieldsets = app(DesignScreen::class)->data()['fieldsets'];

    expect(array_column($fieldsets['site'], 'key'))->toBe(['site_name', 'show_rail', 'rail_count', 'share_whatsapp'])
        ->and(array_column($fieldsets['site'], 'group'))->toBe([null, 'Home', 'Home', 'Share'])
        ->and(array_column($fieldsets['latest'], 'key'))->toBe(['feed_layout'])
        ->and($fieldsets['latest'][0]['group'])->toBeNull();
});
