<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Deployment\DeployLockSession;
use Tests\Fixtures\Deployment\DeployRun;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // DeployRun::start() hands these folders to each child.
    DeployRun::isolatePageCache();
    $this->site = null;
});

afterEach(function (): void {
    DeployLockSession::close();
    $this->site?->close();
});

it('makes a second deploy wait for the first, then find nothing left to migrate, as two processes (I-377)', function (): void {
    $this->site = ScratchSite::open();
    // This part's own migration, made to wait again, as a release that ships one leaves it.
    Schema::drop('release_rebuilds');
    DB::table('migrations')->where('migration', DeployRun::REBUILDS)->delete();
    // Held here first, so both deploys are seen waiting before either runs.
    DeployLockSession::hold();

    $first = DeployRun::start($this->site->database);
    $second = DeployRun::start($this->site->database);
    DeployLockSession::waitForWaiting(2);
    DeployLockSession::close();
    $runs = [DeployRun::finished($first), DeployRun::finished($second)];

    expect(array_map(fn (DeployRun $run): int => $run->status, $runs))->toBe([0, 0])
        ->and(array_map(fn (DeployRun $run): bool => $run->json['lock']['waited'], $runs))->toBe([true, true])
        ->and(array_map(fn (DeployRun $run): array => $run->json['migrations']['ran'], $runs))->toEqualCanonicalizing([[DeployRun::REBUILDS], []])
        ->and(Schema::hasTable('release_rebuilds'))->toBeTrue();
});

it('prints no JSON and exits 1 when a production site can\'t start, saying why on its errors (I-375)', function (): void {
    $this->site = ScratchSite::open();

    $run = DeployRun::finished(DeployRun::start($this->site->database, ['APP_ENV' => 'production', 'APP_KEY' => '']));

    expect($run->status)->toBe(1)
        ->and($run->json)->toBeNull()
        ->and($run->output.$run->errors)->toContain('Teme cannot start in production:')
        ->and($run->output.$run->errors)->toContain('APP_KEY is empty.');
});
