<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('refuses a state or a reason the table doesn’t know, and a reason on an enabled row', function (array $row, string $constraint): void {
    // Its own savepoint, so the count below still runs once the insert fails.
    $insert = fn () => DB::transaction(fn () => DB::table('plugins')->insert([
        'id' => strtolower((string) Str::ulid()), 'name' => 'tests/hello', 'code' => 'hello', 'version' => '1.0.0',
        'consent' => '{}', 'consented_at' => now(), 'faults' => '{}', 'quarantine' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ...$row,
    ]));

    expect($insert)->toThrow(QueryException::class, $constraint);
    expect(DB::table('plugins')->count())->toBe(0);
})->with([
    // PostgreSQL names the first check it tries, and plugins_reason also refuses any other state.
    'a state' => [['state' => 'paused', 'disabled_reason' => null], 'violates check constraint "plugins_'],
    'a reason' => [['state' => 'disabled', 'disabled_reason' => 'tired'], 'plugins_reason'],
    'a reason on an enabled row' => [['state' => 'enabled', 'disabled_reason' => 'by_user'], 'plugins_reason'],
]);

it('keeps an empty fault list as a JSON object', function (): void {
    PluginRecord::factory()->enabled()->create(['faults' => [], 'quarantine' => []]);

    expect(DB::table('plugins')->value('faults'))->toBe('{}')
        ->and(DB::table('plugins')->value('quarantine'))->toBe('{}')
        ->and(PluginRecord::query()->sole()->faults)->toBe([]);
});
