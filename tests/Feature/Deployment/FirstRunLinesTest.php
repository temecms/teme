<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Doctor;
use App\Domain\Users\SetupCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Backups\ScratchSite;
use Tests\Fixtures\Deployment\DeployLockSession;
use Tests\Fixtures\Deployment\DeployRun;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeployRun::isolatePageCache();
    // A database of the test's own, which the deploy migrates outside the test's transaction; it has no account.
    $this->site = ScratchSite::open();
    app()->instance(Doctor::class, new Doctor([]));
});

afterEach(function (): void {
    DeployLockSession::close();
    $this->site->close();
});

it('prints the setup page and its code at the end of a deploy while no account exists', function (): void {
    $run = DeployRun::here();
    $code = app(SetupCode::class)->forThisSite();

    expect($run->status)->toBe(0)
        ->and($run->output)->toEndWith(implode(PHP_EOL, [
            'No one can sign in yet. To make the first administrator, open '.route('first-run.show').' and enter this setup code:',
            '  '.$code,
            'Or run php artisan teme:user:create.',
        ]).PHP_EOL)
        ->and(route('first-run.show'))->not->toContain($code);
});

it('prints nothing more once an account exists, in a check, or as JSON', function (): void {
    $code = app(SetupCode::class)->forThisSite();

    expect(DeployRun::here(['--json' => true])->output)->not->toContain($code)
        ->and(DeployRun::here(['--check' => true])->output)->not->toContain($code);

    User::factory()->create();

    expect(DeployRun::here()->output)->not->toContain($code);
});

it('prints the page and the code again on request, and says when the site is set up', function (): void {
    $code = app(SetupCode::class)->forThisSite();

    expect(Artisan::call('teme:setup-code'))->toBe(0)
        ->and(Artisan::output())->toContain($code);

    User::factory()->create();

    expect(Artisan::call('teme:setup-code'))->toBe(0);
    $closed = Artisan::output();

    expect($closed)->toContain('This site is already set up, so its setup page is closed.')
        ->and($closed)->not->toContain($code);
});

it('says the key is missing, without a stack trace, when there is no application key', function (): void {
    config(['app.key' => '']);

    expect(Artisan::call('teme:setup-code'))->toBe(1)
        ->and(Artisan::output())->toContain('The application key is missing, so there is no setup code. Set APP_KEY, then run this again.');
});
