<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Deployment\SecondStubCheck;
use Tests\Fixtures\Deployment\StubCheck;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->doctor = new Doctor([]);
    app()->instance(Doctor::class, $this->doctor);
});

it('lists errors first, then warnings, what is well and what is noted, each with its fix, and fails on an error (I-157)', function (): void {
    app()->instance(StubCheck::class, new StubCheck('Stub', [new Finding(Severity::Ok, 'All is well here.'), new Finding(Severity::Error, 'Something is broken.', 'Mend it.')]));
    app()->instance(SecondStubCheck::class, new SecondStubCheck('Second stub', [new Finding(Severity::Info, 'Nothing to compare yet.'), new Finding(Severity::Warning, 'Something is odd.', 'Look at it.')]));
    $this->doctor->register('stub', StubCheck::class);
    $this->doctor->register('second-stub', SecondStubCheck::class);

    $status = Artisan::call('teme:doctor');
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toBe(implode(PHP_EOL, [
            '  error    Stub: Something is broken.',
            '           Fix: Mend it.',
            '  warning  Second stub: Something is odd.',
            '           Fix: Look at it.',
            '  ok       Stub: All is well here.',
            '  info     Second stub: Nothing to compare yet.',
            '',
            '1 error, 1 warning.',
            '',
        ]));
});

it('passes with warnings and no errors', function (): void {
    app()->instance(StubCheck::class, new StubCheck('Stub', [new Finding(Severity::Warning, 'Something is odd.', 'Look at it.'), new Finding(Severity::Warning, 'And this.', 'Look again.')]));
    $this->doctor->register('stub', StubCheck::class);

    $status = Artisan::call('teme:doctor');
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toEndWith(PHP_EOL.'0 errors, 2 warnings.'.PHP_EOL);
});

it('reports a check that throws as an error of its own, and runs the rest', function (): void {
    app()->instance(StubCheck::class, new StubCheck('Stub', [], new RuntimeException('Boom.')));
    app()->instance(SecondStubCheck::class, new SecondStubCheck('Second stub', [new Finding(Severity::Ok, 'Fine.')]));
    $this->doctor->register('stub', StubCheck::class);
    $this->doctor->register('second-stub', SecondStubCheck::class);

    $status = Artisan::call('teme:doctor');
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain("  error    Stub: The check couldn't run: Boom.".PHP_EOL)
        ->and($output)->toContain('  ok       Second stub: Fine.'.PHP_EOL);
});

it('refuses a key registered twice', function (): void {
    $this->doctor->register('stub', StubCheck::class);

    expect(fn () => $this->doctor->register('stub', SecondStubCheck::class))
        ->toThrow(LogicException::class, 'A doctor check is already registered as stub.');
});

it('holds core\'s checks, in a fixed order, when the container makes it', function (): void {
    expect((new Doctor)->keys())->toBe(['database-role', 'configuration', 'site-address', 'debug-mode', 'trusted-proxies', 'email', 'password-hashing', 'storage', 'page-cache-folder', 'user', 'scheduler', 'queue-worker']);
});

it('makes what only production needs an error there and a warning elsewhere, in Drupal\'s order', function (): void {
    expect(Severity::inProduction(true))->toBe(Severity::Error)
        ->and(Severity::inProduction(false))->toBe(Severity::Warning)
        ->and(array_map(fn (Severity $severity): int => $severity->value, Severity::cases()))->toBe([-1, 0, 1, 2])
        ->and(array_map(fn (Severity $severity): string => $severity->label(), Severity::cases()))->toBe(['info', 'ok', 'warning', 'error']);
});
