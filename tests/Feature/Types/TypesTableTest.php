<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('holds one row per type, its definition in one JSONB column (rule 2)', function (): void {
    $columns = collect(DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'content_type_definitions' ORDER BY ordinal_position"))
        ->pluck('data_type', 'column_name')
        ->all();

    expect($columns)->toBe([
        'key' => 'character varying',
        'definition' => 'jsonb',
        'created_at' => 'timestamp with time zone',
        'updated_at' => 'timestamp with time zone',
        'plugin' => 'character varying',
    ]);
});

it('refuses a row no type could be read from', function (string $key, string $definition): void {
    expect(fn () => DB::transaction(fn () => DB::table('content_type_definitions')->insert(['key' => $key, 'definition' => $definition, 'created_at' => now(), 'updated_at' => now()])))
        ->toThrow(QueryException::class, 'violates check constraint');
})->with([
    'a key with capitals' => ['Film', '{"segment": "films"}'],
    'a definition that is a list' => ['film', '["films"]'],
    'a definition with no segment' => ['film', '{"singular": "Film"}'],
    'a segment that isn’t text' => ['film', '{"segment": 7}'],
]);

it('refuses two types at one admin address', function (): void {
    $row = fn (string $key): array => ['key' => $key, 'definition' => '{"segment": "films"}', 'created_at' => now(), 'updated_at' => now()];
    DB::table('content_type_definitions')->insert($row('film'));

    expect(fn () => DB::transaction(fn () => DB::table('content_type_definitions')->insert($row('movie'))))
        ->toThrow(UniqueConstraintViolationException::class);
});
