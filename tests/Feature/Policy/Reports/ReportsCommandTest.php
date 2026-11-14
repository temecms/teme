<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    // A row last seen this many days ago.
    $this->row = fn (string $blocked, float $days, int $count = 1, string $directive = 'img-src'): bool => DB::table('csp_reports')->insert([
        'directive' => $directive, 'blocked' => $blocked, 'page' => '/about', 'disposition' => 'report', 'count' => $count,
        'sample' => null, 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subSeconds((int) ($days * 86_400)),
    ]);
});

it('lists the last seven days’ rows, newest first, at most a hundred', function (): void {
    ($this->row)('https://eight.example', 8);
    ($this->row)('https://old.example', 3, 5);
    ($this->row)('https://new.example', 1, 2, 'font-src');

    $status = Artisan::call('teme:csp:reports');
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('Directive', 'Blocked', 'Page', 'Times', 'Last seen', 'Mode')
        ->and($output)->toContain('https://new.example', 'https://old.example', '/about', 'report only')
        ->and($output)->not->toContain('https://eight.example')
        ->and(strpos($output, 'https://new.example'))->toBeLessThan(strpos($output, 'https://old.example'));

    foreach (range(1, 105) as $n) {
        ($this->row)("https://many{$n}.example", 1 + $n / 1000);
    }

    Artisan::call('teme:csp:reports');
    $many = Artisan::output();

    expect(substr_count($many, 'https://'))->toBe(100);

    DB::table('csp_reports')->update(['disposition' => 'enforce']);
    Artisan::call('teme:csp:reports');

    expect(Artisan::output())->toContain('enforced');
});

it('takes --days from 1 to 365, and refuses others in these words', function (): void {
    ($this->row)('https://forty.example', 40);

    expect(Artisan::call('teme:csp:reports', ['--days' => '365']))->toBe(0)
        ->and(Artisan::output())->toContain('https://forty.example');

    foreach (['0', '366', 'x', '1.5', '-3'] as $bad) {
        $status = Artisan::call('teme:csp:reports', ['--days' => $bad]);
        $output = Artisan::output();

        expect($status)->toBe(1)
            ->and($output)->toContain('--days takes a whole number from 1 to 365.');
    }
});

it('says so when nothing was reported, in these words, for one day and for many', function (): void {
    Artisan::call('teme:csp:reports', ['--days' => '1']);
    $one = Artisan::output();
    Artisan::call('teme:csp:reports');
    $seven = Artisan::output();

    expect($one)->toContain('Nothing was reported in the last day.')
        ->and($seven)->toContain('Nothing was reported in the last 7 days.');
});

it('prints a cell holding console tags or an escape sequence as plain text', function (): void {
    ($this->row)('<href=https://evil.example>x</>', 1);
    ($this->row)('<fg=red>red</>', 2);
    ($this->row)("a\e[31mb\e]8;;https://evil.example\e\\c", 3);
    ($this->row)("bell\x07and\u{200B}zero-width", 4);

    Artisan::call('teme:csp:reports');
    $output = Artisan::output();

    expect($output)->toContain('<href=https://evil.example>x</>')
        ->and($output)->toContain('<fg=red>red</>')
        ->and($output)->toContain('a[31mb]8;;https://evil.example')
        ->and($output)->toContain('bellandzero-width')
        ->and($output)->not->toContain("\e");
});
