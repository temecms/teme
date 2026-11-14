<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * Stores the addresses group's overrides by hand, as a site saved them before
 * this migration ran.
 *
 * @param  array<string, string>  $overrides
 */
function nestStore(array $overrides): void
{
    DB::table('settings')->updateOrInsert(['group_key' => 'addresses'], ['overrides' => json_encode($overrides), 'version' => 3, 'updated_at' => now()]);
}

function nestRun(): void
{
    (require database_path('migrations/2027_03_20_100400_nest_page_addresses.php'))->up();
}

/**
 * @return array<string, mixed>
 */
function nestOverrides(): array
{
    return json_decode((string) DB::table('settings')->where('group_key', 'addresses')->value('overrides'), true);
}

it('nests a stored /{slug}/ for pages and records the change as the system', function (): void {
    nestStore(['pattern_page' => '/{slug}/', 'pattern_post' => '/{year}/{slug}/']);

    nestRun();

    $change = DB::table('settings_changes')->where('group_key', 'addresses')->sole();

    expect(nestOverrides())->toEqualCanonicalizing(['pattern_page' => '/{parent}/{slug}/', 'pattern_post' => '/{year}/{slug}/'])
        ->and((int) DB::table('settings')->where('group_key', 'addresses')->value('version'))->toBe(4)
        ->and($change->origin)->toBe('system')
        ->and($change->actor_id)->toBeNull()
        ->and(json_decode((string) $change->changed_keys, true))->toEqualCanonicalizing(['pattern_page'])
        ->and(json_decode((string) $change->before, true))->toEqualCanonicalizing(['pattern_page' => '/{slug}/'])
        ->and(json_decode((string) $change->after, true))->toEqualCanonicalizing(['pattern_page' => '/{parent}/{slug}/']);
});

it('nests /{slug} without its slash', function (): void {
    nestStore(['pattern_page' => '/{slug}']);

    nestRun();

    expect(nestOverrides()['pattern_page'])->toBe('/{parent}/{slug}');
});

it('leaves a site that never saved the pattern alone, which reads the new default', function (): void {
    nestRun();

    expect(DB::table('settings')->where('group_key', 'addresses')->exists())->toBeFalse()
        ->and(DB::table('settings_changes')->count())->toBe(0);

    nestStore(['pattern_post' => '/{slug}/']);
    nestRun();

    expect(nestOverrides())->toEqualCanonicalizing(['pattern_post' => '/{slug}/'])
        ->and(DB::table('settings_changes')->count())->toBe(0);
});

it('leaves any other stored pattern alone', function (): void {
    nestStore(['pattern_page' => '/pages/{slug}/']);

    nestRun();

    expect(nestOverrides()['pattern_page'])->toBe('/pages/{slug}/')
        ->and((int) DB::table('settings')->where('group_key', 'addresses')->value('version'))->toBe(3)
        ->and(DB::table('settings_changes')->count())->toBe(0);
});

it('moves no address', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $page = addrPublished($editor, 'About', 'page');
    $before = DB::table('paths')->where('target_id', $page->id)->get()->map(fn (object $row): array => (array) $row)->all();
    nestStore(['pattern_page' => '/{slug}/']);

    nestRun();

    expect(DB::table('paths')->where('target_id', $page->id)->get()->map(fn (object $row): array => (array) $row)->all())->toBe($before)
        ->and(addrCurrent('content', $page->id))->toBe('about');
});
