<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->fake = importFake();
    importPeople();
});

it('connects a site and starts its import as the operator, asking for the application password without showing it', function (): void {
    $this->artisan('teme:import', ['address' => 'old.example', '--user' => FakeCompanion::USER, '--pause' => '0'])
        ->expectsQuestion('The application password for ada on old.example (it isn’t shown as you type)', FakeCompanion::PASSWORD)
        ->expectsOutput('Connected to https://old.example (Teme Companion 0.2.0).')
        ->expectsOutput('Started importing https://old.example. A queue worker carries it out: see how it’s going with php artisan teme:import https://old.example --status.')
        ->assertSuccessful();

    expect(ImportRun::query()->sole()->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('fixture_people')->count())->toBe(3)
        ->and(DB::table('security_events')->where('type', 'import.started')->value('user_id'))->toBeNull();
});

it('uses the password it keeps, and starts a catch-up of a site it has imported', function (): void {
    importRun(importSource());

    $this->artisan('teme:import', ['address' => 'https://old.example', '--catch-up' => true, '--pause' => '0'])
        ->expectsOutput('Started a catch-up of https://old.example. A queue worker carries it out: see how it’s going with php artisan teme:import https://old.example --status.')
        ->assertSuccessful();

    expect(ImportRun::query()->where('kind', ImportRunKind::CatchUp->value)->sole()->state)->toBe(ImportRunState::Finished);
});

it('says how the last import went', function (): void {
    importRun(importSource());
    $run = ImportRun::query()->sole();

    $this->artisan('teme:import', ['address' => 'https://old.example', '--status' => true])
        ->expectsOutput('Full import, finished.')
        ->expectsOutput("Started {$run->started_at->utc()->format('Y-m-d H:i')} UTC, ended {$run->ended_at?->utc()->format('Y-m-d H:i')} UTC.")
        ->expectsOutput('3 created, 0 updated, 1 skipped, 1 failed, 4 with warnings.')
        ->assertSuccessful();
});

it('stops an import, carries it on, and forgets the site once it has stopped, as the operator', function (): void {
    $source = importSource();
    Queue::fake();
    scElevated(fn () => app(StartImportRun::class)->execute(new StartImportRunInput($source->id, ImportRunKind::Full, ['pause_ms' => 0])));

    $this->artisan('teme:import', ['address' => 'https://old.example', '--stop' => true])
        ->expectsOutput('Stopped the import of https://old.example. Carry it on with php artisan teme:import https://old.example --continue.')
        ->assertSuccessful();
    $this->artisan('teme:import', ['address' => 'https://old.example', '--continue' => true])
        ->expectsOutput('Carrying on the import of https://old.example.')
        ->assertSuccessful();
    $this->artisan('teme:import', ['address' => 'https://old.example', '--forget' => true])
        ->expectsOutput('Stop the import that’s running first: it needs the application password.')
        ->assertFailed();
    $this->artisan('teme:import', ['address' => 'https://old.example', '--stop' => true])->assertSuccessful();
    $this->artisan('teme:import', ['address' => 'https://old.example', '--forget' => true])
        ->expectsOutput('Teme has forgotten the application password for https://old.example. Revoke it in WordPress too: Users › Profile › Application Passwords.')
        ->assertSuccessful();

    expect($source->fresh()->credential)->toBeNull();
});

it('refuses what it can\'t do, in plain words, and starts nothing', function (string $case, string $words): void {
    $arguments = match ($case) {
        'unknown' => ['address' => 'https://old.example', '--status' => true],
        'no user' => ['address' => 'https://old.example'],
        'two actions' => ['address' => 'https://old.example', '--stop' => true, '--forget' => true],
        default => ['address' => 'https://old.example', '--user' => FakeCompanion::USER, '--no-interaction' => true],
    };

    $this->artisan('teme:import', $arguments)->expectsOutput($words)->assertFailed();

    expect(DB::table('import_runs')->count())->toBe(0);
})->with([
    'a site Teme doesn\'t know' => ['unknown', 'Teme doesn’t know https://old.example yet. Connect it first: php artisan teme:import https://old.example --user=NAME'],
    'no user name to connect with' => ['no user', 'Give a WordPress administrator’s user name with --user.'],
    'two actions at once' => ['two actions', 'Choose one of --catch-up, --continue, --stop, --forget and --status.'],
    'no terminal to ask the password in' => ['no terminal', 'Teme needs the application password for https://old.example, and can’t ask for it without a terminal.'],
]);

it('shows a refusal in WordPress\'s words turned into Teme\'s, and keeps nothing', function (): void {
    $this->artisan('teme:import', ['address' => 'https://old.example', '--user' => FakeCompanion::USER])
        ->expectsQuestion('The application password for ada on https://old.example (it isn’t shown as you type)', 'wrong wrong wrong')
        ->expectsOutput('WordPress didn’t accept this user name and application password. If both are right, the site’s server may be removing the sign-in header before WordPress sees it.')
        ->assertFailed();

    expect(DB::table('import_sources')->count())->toBe(0);
});
