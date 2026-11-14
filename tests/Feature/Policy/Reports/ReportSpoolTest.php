<?php

declare(strict_types=1);

use App\Domain\Site\Policy\Reports\PolicyReport;
use App\Domain\Site\Policy\Reports\PolicyReportSpool;

beforeEach(function (): void {
    $this->folder = cacheOn();
    $this->spool = app(PolicyReportSpool::class);
    $this->open = fn (): string => $this->spool->folder().'/'.PolicyReportSpool::OPEN;
});

afterEach(fn () => cacheOff($this->folder));

it('appends one whole line per report, with tabs and line breaks encoded', function (): void {
    $this->spool->append(new PolicyReport('script-src-elem', 'inline', "/a\tb\nc", 'report', ['sample' => "x\ty\nz"], 1_700_000_000));
    $this->spool->append(new PolicyReport('img-src', 'https://px.example.net', '/about', 'enforce', null, 1_700_000_001));

    $lines = file(($this->open)(), FILE_IGNORE_NEW_LINES);

    expect($lines)->toHaveCount(2);

    $cells = explode("\t", $lines[0]);

    expect($cells)->toHaveCount(6)
        ->and($cells[0])->toBe('script-src-elem')
        ->and(rawurldecode($cells[2]))->toBe("/a\tb\nc")
        ->and(json_decode(rawurldecode($cells[4]), true))->toBe(['sample' => "x\ty\nz"])
        ->and($cells[5])->toBe('1700000000')
        ->and(explode("\t", $lines[1]))->toHaveCount(6)
        ->and(explode("\t", $lines[1])[4])->toBe('');
});

it('takes no more lines past its size', function (): void {
    $this->spool->append(new PolicyReport('img-src', 'https://a.example', '/', 'report', null, 1));
    $handle = fopen(($this->open)(), 'r+');
    ftruncate($handle, PolicyReportSpool::MOST_BYTES + 1);
    fclose($handle);
    clearstatcache();
    $size = filesize(($this->open)());

    $this->spool->append(new PolicyReport('img-src', 'https://b.example', '/', 'report', null, 2));
    clearstatcache();

    expect(filesize(($this->open)()))->toBe($size);
});

it('makes its folder when it is missing', function (): void {
    expect(is_dir($this->spool->folder()))->toBeFalse();

    $this->spool->append(new PolicyReport('img-src', 'https://a.example', '/', 'report', null, 1));

    expect(is_file(($this->open)()))->toBeTrue()
        ->and($this->spool->folder())->toEndWith('/csp');
});

it('drops a line it can’t write, saying nothing', function (): void {
    // A file where the folder should be: neither the append nor the mkdir can succeed, whoever runs the test.
    mkdir($this->folder, 0775, true);
    file_put_contents($this->spool->folder(), 'in the way');

    try {
        $this->spool->append(new PolicyReport('img-src', 'https://a.example', '/', 'report', null, 1));
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull()
        ->and(is_file($this->spool->folder()))->toBeTrue()
        ->and(file_get_contents($this->spool->folder()))->toBe('in the way');
});
