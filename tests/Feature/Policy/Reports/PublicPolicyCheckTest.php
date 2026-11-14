<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Doctor;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Site\Policy\Reports\PolicyReportSpool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    // The check's one finding, for a mode and the rows reported in the last day, one row each.
    $this->examine = function (string $mode, int $reported, bool $production = false): array {
        config(['teme.public_csp' => $mode]);
        app()->detectEnvironment(fn (): string => $production ? 'production' : 'testing');
        DB::table('csp_reports')->delete();

        foreach (range(1, max($reported, 1)) as $n) {
            DB::table('csp_reports')->insert([
                'directive' => 'img-src', 'blocked' => "https://n{$n}.example", 'page' => '/', 'disposition' => 'report', 'count' => 1,
                'sample' => null, 'first_seen_at' => now()->subHours(2), 'last_seen_at' => $n > $reported ? now()->subDays(3) : now()->subHours(2),
            ]);
        }

        return app(Doctor::class)->examineOnly(['public-csp']);
    };
});

it('is registered as public-csp, and not among core’s checks', function (): void {
    $results = ($this->examine)('report-only', 0);

    expect($results)->toHaveCount(1)
        ->and($results[0]->key)->toBe('public-csp')
        ->and($results[0]->title)->toBe("Public site's security policy")
        ->and(app(Doctor::class)->keys())->toContain('public-csp')
        ->and((new Doctor)->keys())->not->toContain('public-csp');
});

it('says what each mode finds, in these words', function (string $mode, int $reported, bool $production, Severity $severity, string $message): void {
    $finding = ($this->examine)($mode, $reported, $production)[0]->finding;

    expect($finding->severity)->toBe($severity)
        ->and($finding->message)->toBe($message);
})->with([
    'off, in production' => ['off', 0, true, Severity::Warning, 'The public site sends no content security policy (TEME_PUBLIC_CSP=off).'],
    'off, elsewhere' => ['off', 0, false, Severity::Info, 'The public site sends no content security policy (TEME_PUBLIC_CSP=off).'],
    'unknown' => ['Strict', 0, true, Severity::Warning, 'TEME_PUBLIC_CSP is “strict”, which Teme doesn\'t know, so the policy only reports. Use off, report-only or enforce.'],
    'report-only, none' => ['report-only', 0, false, Severity::Ok, 'Report only, and nothing reported in the last day.'],
    'report-only, one' => ['report-only', 1, false, Severity::Warning, 'One thing the policy would block was reported in the last day. See php artisan teme:csp:reports before you enforce it.'],
    'report-only, three' => ['report-only', 3, false, Severity::Warning, '3 things the policy would block were reported in the last day. See php artisan teme:csp:reports before you enforce it.'],
    'enforce, none' => ['enforce', 0, false, Severity::Ok, 'Enforced, and nothing blocked in the last day.'],
    'enforce, one' => ['enforce', 1, false, Severity::Warning, 'The policy blocked one thing in the last day. See php artisan teme:csp:reports.'],
    'enforce, two' => ['enforce', 2, false, Severity::Warning, 'The policy blocked 2 things in the last day. See php artisan teme:csp:reports.'],
]);

it('warns when a file of reports was set aside, whatever the mode', function (string $mode): void {
    $folder = cacheOn();
    $spool = app(PolicyReportSpool::class);
    mkdir($spool->folder(), 0775, true);

    try {
        $clean = ($this->examine)($mode, 0);
        file_put_contents($spool->folder().'/csp.0000000001-abcd.bad', "x\n");
        $one = ($this->examine)($mode, 0);
        file_put_contents($spool->folder().'/csp.0000000002-abcd.bad', "x\n");
        $two = ($this->examine)($mode, 0);
    } finally {
        cacheOff($folder);
    }

    $messages = fn (array $results): array => array_map(fn ($result): string => $result->finding->message, $results);

    expect($messages($clean))->not->toContain('A file of policy reports could not be recorded and was set aside.')
        ->and($messages($one))->toContain('A file of policy reports could not be recorded and was set aside.')
        ->and($messages($two))->toContain('2 files of policy reports could not be recorded and were set aside.')
        ->and(collect($one)->first(fn ($result): bool => str_starts_with($result->finding->message, 'A file of policy'))->finding->severity)->toBe(Severity::Warning);
})->with(['off', 'report-only', 'enforce']);
