<?php

declare(strict_types=1);

use App\Domain\Deployment\DeployOutcome;
use App\Domain\Deployment\StartRefused;
use App\Domain\Deployment\StartRules;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->argv = $_SERVER['argv'] ?? [];
});

afterEach(function (): void {
    $_SERVER['argv'] = $this->argv;
});

it('is a command that starts a role, so a production site that can\'t start refuses to deploy (I-375)', function (): void {
    expect(StartRules::ROLE_COMMANDS)->toContain('teme:deploy')
        ->and(Artisan::all())->toHaveKey('teme:deploy');

    app()->instance('env', 'production');
    config(['app.key' => '']);
    $_SERVER['argv'] = ['artisan', 'teme:deploy'];

    expect(fn () => StartRules::enforce(app()))->toThrow(StartRefused::class);
});

it('exits with one code per outcome, the contract a program reads (I-3189)', function (DeployOutcome $outcome, string $value, int $code): void {
    expect($outcome->value)->toBe($value)
        ->and($outcome->exitCode())->toBe($code);
})->with([
    [DeployOutcome::Deployed, 'deployed', 0],
    [DeployOutcome::Current, 'current', 0],
    [DeployOutcome::Failed, 'failed', 1],
    [DeployOutcome::Refused, 'refused', 2],
    [DeployOutcome::BackupFailed, 'backup-failed', 3],
    [DeployOutcome::MigrationFailed, 'migration-failed', 4],
    [DeployOutcome::Unfinished, 'unfinished', 5],
    [DeployOutcome::Pending, 'pending', 6],
    [DeployOutcome::Busy, 'busy', 7],
]);
