<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSettings;
use App\Domain\Export\Kinds\ContentsKind;
use App\Domain\Export\Kinds\MenuItemsKind;
use App\Domain\Export\Kinds\MenusKind;
use App\Domain\Export\Kinds\RedirectsKind;
use App\Domain\Export\Kinds\SettingsKind;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingType;
use Tests\Fixtures\Export\ExportAs;
use Tests\Fixtures\Security\SealedSecrets;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->line = fn (array $lines, string $key, string $value): array => collect($lines)->firstWhere($key, $value) ?? [];
});

it('carries each menu, and each of its links by what it points to, nested by parent', function (): void {
    $about = addrPublished($this->admin, 'About the harbour', 'page');
    $boats = taxonomyTerm('category', 'Boats');
    $menu = navCreate($this->admin, 'Main');
    navSave($this->admin, $menu, [
        ['kind' => 'home'],
        ['kind' => 'content', 'target_id' => $about->id, 'label' => 'About', 'children' => [
            ['kind' => 'term', 'target_id' => $boats->id],
        ]],
        ['kind' => 'url', 'url' => 'https://tides.example.org/', 'label' => 'Tide tables', 'opens_new_tab' => true],
    ]);

    $menus = ExportAs::kindLines(app(MenusKind::class));
    $items = ExportAs::kindLines(app(MenuItemsKind::class), pageSize: 2);
    $aboutLink = ($this->line)($items, 'target_id', $about->id);
    $boatsLink = ($this->line)($items, 'target_id', $boats->id);
    $tides = ($this->line)($items, 'link_kind', 'url');

    expect($menus)->toHaveCount(1)
        ->and($menus[0])->toMatchArray(['id' => $menu->id, 'name' => 'Main', 'handle' => $menu->handle])
        ->and($items)->toHaveCount(4)
        ->and(array_unique(array_column($items, 'menu_id')))->toBe([$menu->id])
        ->and($aboutLink)->toMatchArray(['link_kind' => 'content', 'parent_id' => null, 'position' => 1, 'label' => 'About', 'url' => null])
        ->and($boatsLink)->toMatchArray(['link_kind' => 'term', 'parent_id' => $aboutLink['id'], 'position' => 0, 'label' => null])
        ->and($tides)->toMatchArray(['url' => 'https://tides.example.org/', 'target_id' => null, 'opens_new_tab' => true])
        ->and(($this->line)($items, 'link_kind', 'home')['target_id'])->toBeNull();
});

it('carries each redirect with its target by ID or address, and those that send readers nowhere', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    $toAddress = rdSaved('/old-harbour', '/about-the-harbour/');
    $toItem = rdSaved('/older-news', '', 301, item: $post->id);
    $gone = rdSaved('/closed', '', 410);

    $lines = ExportAs::kindLines(app(RedirectsKind::class), pageSize: 2);

    expect(array_column($lines, 'id'))->toEqualCanonicalizing([$toAddress, $toItem, $gone])
        ->and(($this->line)($lines, 'id', $toItem))->toMatchArray(['source' => 'older-news', 'match' => 'exact', 'status' => 301, 'target_kind' => 'content', 'target' => $post->id, 'enabled' => true, 'origin' => 'manual', 'hits' => 0])
        ->and(($this->line)($lines, 'id', $toAddress))->toMatchArray(['target_kind' => 'address'])
        ->and(($this->line)($lines, 'id', $gone))->toMatchArray(['status' => 410, 'target_kind' => null, 'target' => null])
        ->and(array_keys($lines[0]))->not->toContain('target_path');
});

it('carries every settings group’s values, and never a secret one or a list that can hold IP addresses', function (): void {
    SealedSecrets::settings(BackupSettings::GROUP, ['machine_key' => str_repeat('b', 64)], app(Encrypter::class));
    cmSettings(['hold_list' => '198.51.100.7', 'block_list' => '198.51.100.8']);
    $registry = app(SettingsRegistry::class);

    $lines = ExportAs::kindLines(app(SettingsKind::class));
    $written = json_encode($lines, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $secretKeys = [];

    foreach ($registry->all() as $group) {
        foreach ($group->settings as $setting) {
            if ($setting->type === SettingType::Secret) {
                $secretKeys[] = [$group->key, $setting->key];
            }
        }
    }

    expect(array_column($lines, 'group'))->toBe(array_map(fn ($group): string => $group->key, $registry->all()))
        ->and(($this->line)($lines, 'group', 'general')['values']->site_title)->toBe(pageSiteName())
        ->and($secretKeys)->toContain([BackupSettings::GROUP, 'machine_key'])
        ->and($written)->not->toContain(str_repeat('b', 64))
        ->and($written)->not->toContain('198.51.100.7')
        ->and($written)->not->toContain('198.51.100.8');

    foreach ([...$secretKeys, ['discussion', 'hold_list'], ['discussion', 'block_list']] as [$group, $key]) {
        expect(property_exists(($this->line)($lines, 'group', $group)['values'], $key))->toBeFalse();
    }
});

it('fills no cache while it reads, so nothing read in its moment outlives it', function (): void {
    // The database store, as production runs: a cache row written inside the
    // export's snapshot would keep that snapshot's value for good.
    config(['cache.default' => 'database']);
    Cache::purge('database');
    addrPublished($this->admin, 'Harbour news');

    // Resolved before the moment, as ExportSite resolves its kinds when it is built.
    $kinds = [app(ContentsKind::class), app(SettingsKind::class)];
    $before = DB::table('cache')->pluck('key')->all();

    foreach ($kinds as $kind) {
        ExportAs::kindLines($kind);
    }

    expect(DB::table('cache')->pluck('key')->all())->toEqualCanonicalizing($before);
});
