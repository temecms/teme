<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->row = fn (array $columns = []): array => array_replace([
        'id' => strtolower((string) Str::ulid()),
        'source' => 'old-fares',
        'kind' => 'exact',
        'pattern' => null,
        'status' => 301,
        'target_kind' => 'address',
        'target' => '/news/fares/',
        'target_path' => 'news/fares',
        'origin' => 'manual',
        'enabled' => true,
        'note' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ], $columns);
});

it('takes a redirect that holds together, and a 410 that sends readers nowhere', function (): void {
    expect(cmRefusal(fn () => DB::table('redirects')->insert(($this->row)())))->toBeNull()
        ->and(cmRefusal(fn () => DB::table('redirects')->insert(($this->row)(['source' => 'withdrawn', 'status' => 410, 'target_kind' => null, 'target' => null, 'target_path' => null]))))->toBeNull()
        ->and(DB::table('redirects')->count())->toBe(2);
});

it('refuses a row that doesn\'t hold together, whoever writes it', function (array $columns): void {
    expect(cmRefusal(fn () => DB::table('redirects')->insert(($this->row)($columns))))->toBe('23514');
})->with([
    'a kind it doesn\'t know' => [['kind' => 'regex']],
    'a pattern with no regex' => [['kind' => 'pattern', 'source' => '2019/[slug]']],
    'an exact source with a regex' => [['pattern' => '^old-fares$']],
    'a status no redirect has' => [['status' => 404]],
    'a 301 that sends readers nowhere' => [['target_kind' => null, 'target' => null, 'target_path' => null]],
    'a 410 that sends readers somewhere' => [['status' => 410]],
    'an item with an address\'s path' => [['target_kind' => 'content', 'target' => '01jvm0000000000000000000aa']],
    'the home page as its source' => [['source' => '']],
]);

it('keeps one redirect a source', function (): void {
    DB::table('redirects')->insert(($this->row)());

    expect(cmRefusal(fn () => DB::table('redirects')->insert(($this->row)(['target' => '/elsewhere/', 'target_path' => 'elsewhere']))))->toBe('23505');
});
