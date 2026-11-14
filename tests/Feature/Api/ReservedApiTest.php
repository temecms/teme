<?php

declare(strict_types=1);

use App\Domain\Addresses\Paths;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Status\Checks\ApiAddressTaken;
use App\Domain\Status\Checks\QueueWorkerRunning;
use App\Domain\Status\Checks\SchedulerRunning;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Deployment\HealthRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The address settings row changed straight in the table, as an older site
 * holds it, and read again.
 *
 * @param  array<string, string>  $values
 */
function rapiOlderSettings(array $values): void
{
    cmSettings($values, 'addresses');
    app()->forgetScopedInstances();
}

it('refuses the address /api/ to an item, with the words for the site\'s own addresses', function (): void {
    $someone = '01jvm0000000000000000000aa';

    expect(Paths::conflict('api', 'content', $someone))->toBe('The address /api/ is used by the site itself.')
        ->and(Paths::conflict('api/v1', 'content', $someone))->toBe('The address /api/v1/ is used by the site itself.')
        ->and(Paths::conflict('llms.txt', 'content', $someone))->toBe('The address /llms.txt/ is used by the site itself.');
});

it('refuses api as a taxonomy base and as the first segment of a pattern', function (): void {
    expect(fn () => addrChange($this->admin, ['bases' => ['tag' => 'api']]))
        ->toThrow(ValidationException::class, 'Addresses starting /api/ are used by the site itself.')
        ->and(fn () => addrChange($this->admin, ['patterns' => ['post' => '/api/{slug}/']]))
        ->toThrow(ValidationException::class, 'Addresses starting /api/ are used by the site itself.');
});

it('shows no banner while nothing has the address /api/', function (): void {
    addrPublished($this->admin, 'Apiary', 'page');

    expect(app(ApiAddressTaken::class)->banner())->toBeNull()
        ->and(app(StatusChecks::class)->forUser($this->admin)?->priority)->not->toBe(ApiAddressTaken::PRIORITY);
});

it('warns whoever manages addresses when an item already has the address /api/', function (): void {
    $page = addrPublished($this->admin, 'Developer docs', 'page');
    DB::table('paths')->where('target_type', 'content')->where('target_id', $page->id)->where('is_current', true)->update(['path' => 'api']);
    $editor = User::factory()->create()->assignRole('editor');

    $check = app(ApiAddressTaken::class);

    expect($check->ability())->toBe('settings.addresses.manage')
        ->and($check->banner())->toEqual(new StatusBanner(
            'warning',
            'Teme’s API now answers every address starting /api/, so the page "Developer docs" can’t be reached at /api/ any more. Give it another address.',
            'Edit it',
            route('content.edit', ['content' => $page->id], false),
            ApiAddressTaken::PRIORITY,
        ))
        ->and($this->admin->can('settings.addresses.manage'))->toBeTrue()
        ->and($editor->can('settings.addresses.manage'))->toBeFalse()
        ->and(app(StatusChecks::class)->forUser($editor)?->priority)->not->toBe(ApiAddressTaken::PRIORITY);
});

it('warns when a taxonomy base, the author base or a type\'s archive is api', function (array $settings, string $named): void {
    ctCreate($this->admin, ['key' => 'film', 'singular' => 'Film', 'plural' => 'Films', 'segment' => 'films', 'archive' => true]);
    rapiOlderSettings($settings);

    expect(app(ApiAddressTaken::class)->banner())->toEqual(new StatusBanner(
        'warning',
        "Teme’s API now answers every address starting /api/, so {$named} can’t use it any more. Choose another in Settings › Addresses.",
        'Open Addresses',
        route('settings.addresses.show', [], false),
        ApiAddressTaken::PRIORITY,
    ));
})->with([
    'a taxonomy base' => [['base_category' => 'api'], 'the base of Categories'],
    'the author base' => [['author_base' => 'api'], 'the author base'],
    'a type\'s archive' => [['archive_film' => 'api'], 'the archive page of Films'],
    'a pattern' => [['pattern_film' => '/api/{slug}/'], 'the address pattern of Films'],
]);

it('warns when an old address or a redirect starts with /api/, which no longer leads anywhere', function (string $case): void {
    if ($case === 'an old address') {
        $page = addrPublished($this->admin, 'Docs', 'page');
        DB::table('paths')->insert(['path' => 'api/docs', 'target_type' => 'content', 'target_id' => $page->id, 'is_current' => false, 'replaced_at' => now()]);
    } else {
        DB::table('redirects')->insert([
            'id' => (string) Str::ulid(), 'source' => 'api/docs', 'kind' => 'exact', 'status' => 301,
            'target_kind' => 'address', 'target' => '/docs/', 'target_path' => 'docs', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $banner = app(ApiAddressTaken::class)->banner();

    expect($banner?->message)->toBe('Teme’s API now answers every address starting /api/, so the old address /api/docs/ no longer leads anywhere. Point the links to it at the new address, or remove it in Settings › Redirects.')
        ->and($banner?->actionLabel)->toBe('Open Redirects')
        ->and($banner?->actionUrl)->toBe(route('tools.redirects.index', [], false));
})->with(['an old address', 'a redirect']);

it('ranks below the server\'s banners and above a backup caution', function (): void {
    expect(ApiAddressTaken::PRIORITY)->toBe(70)
        ->and(ApiAddressTaken::PRIORITY)->toBeLessThan(QueueWorkerRunning::PRIORITY)
        ->and(ApiAddressTaken::PRIORITY)->toBeLessThan(SchedulerRunning::PRIORITY);
});

it('gives way to the queue\'s banner when a worker is missing too', function (): void {
    $page = addrPublished($this->admin, 'Developer docs', 'page');
    DB::table('paths')->where('target_type', 'content')->where('target_id', $page->id)->where('is_current', true)->update(['path' => 'api']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    app()->instance('env', 'production');
    HealthRows::beat(Heartbeats::SCHEDULER, CarbonImmutable::parse('2026-10-20 12:00:00'));
    HealthRows::job('default', CarbonImmutable::parse('2026-10-20 11:46:00'));

    expect(app(ApiAddressTaken::class)->banner())->not->toBeNull()
        ->and(app(QueueWorkerRunning::class)->banner())->not->toBeNull()
        ->and(app(StatusChecks::class)->forUser($this->admin)?->priority)->toBe(QueueWorkerRunning::PRIORITY);
});

it('names a kind this release doesn’t know at /api/ in words, with nothing to edit, never failing', function (): void {
    // A kind a later release adds, with a ULID.
    DB::table('paths')->insert(['path' => 'api', 'target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d', 'is_current' => true]);

    expect(app(ApiAddressTaken::class)->banner())->toEqual(new StatusBanner(
        'warning',
        'Teme’s API now answers every address starting /api/, so something this version of Teme doesn’t know (gadget) can’t be reached at /api/ any more. Give it another address.',
        'Edit it',
        null,
        ApiAddressTaken::PRIORITY,
    ));
});
