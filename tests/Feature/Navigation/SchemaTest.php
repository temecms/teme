<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// One failing insert per test: after an error, PostgreSQL refuses every later
// statement in RefreshDatabase's transaction, so a second insert would throw anyway.
it('refuses a url item without a url, and a content or term item without a target', function (string $kind): void {
    $nav = (string) Str::ulid();
    DB::table('navigations')->insert(['id' => $nav, 'name' => 'Main', 'handle' => 'main', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('navigation_items')->insert(['id' => (string) Str::ulid(), 'navigation_id' => $nav, 'position' => 0, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'violates check constraint');
})->with(['url', 'content', 'term']);

it('refuses a heading with a target, an address or no label of its own', function (array $columns): void {
    $nav = (string) Str::ulid();
    DB::table('navigations')->insert(['id' => $nav, 'name' => 'Main', 'handle' => 'main', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('navigation_items')->insert(['id' => (string) Str::ulid(), 'navigation_id' => $nav, 'position' => 0, 'kind' => 'heading', 'created_at' => now(), 'updated_at' => now(), ...$columns]))
        ->toThrow(QueryException::class, 'navigation_items_heading_check');
})->with([
    'a target' => [['label' => 'Visit', 'target_id' => '01j00000000000000000000000']],
    'an address' => [['label' => 'Visit', 'url' => '/visit/']],
    'no label' => [['label' => null]],
    'a blank label' => [['label' => '   ']],
]);

it('takes a heading with a label and nothing else', function (): void {
    $nav = (string) Str::ulid();
    DB::table('navigations')->insert(['id' => $nav, 'name' => 'Main', 'handle' => 'main', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('navigation_items')->insert(['id' => (string) Str::ulid(), 'navigation_id' => $nav, 'position' => 0, 'kind' => 'heading', 'label' => 'Visit', 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('navigation_items')->where('kind', 'heading')->value('label'))->toBe('Visit');
});

it('removes a menu\'s items with the menu', function (): void {
    $nav = (string) Str::ulid();
    DB::table('navigations')->insert(['id' => $nav, 'name' => 'Main', 'handle' => 'main', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('navigation_items')->insert(['id' => (string) Str::ulid(), 'navigation_id' => $nav, 'position' => 0, 'kind' => 'home', 'created_at' => now(), 'updated_at' => now()]);

    DB::table('navigations')->where('id', $nav)->delete();

    expect(DB::table('navigation_items')->count())->toBe(0);
});
