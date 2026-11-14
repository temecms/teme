<?php

declare(strict_types=1);

use App\Domain\Site\Policy\Reports\PolicyReport;
use App\Domain\Site\Policy\Reports\PolicyReportReader;
use Tests\Fixtures\Policy\Reports;

beforeEach(function (): void {
    config(['app.url' => 'https://ledger.example']);
    $this->read = fn (?string $type, string $body): array => PolicyReportReader::read($type, $body);
});

it('reads a level 2 report', function (): void {
    $reports = ($this->read)('application/csp-report', Reports::level2());

    expect($reports)->toHaveCount(1)
        ->and($reports[0])->toBeInstanceOf(PolicyReport::class)
        ->and($reports[0]->directive)->toBe('img-src')
        ->and($reports[0]->blocked)->toBe('https://px.example.net')
        ->and($reports[0]->path)->toBe('/about')
        ->and($reports[0]->disposition)->toBe('report');

    $home = ($this->read)('application/csp-report', Reports::level2(['document-uri' => 'https://ledger.example/']));

    expect($home[0]->path)->toBe('/');
});

it('reads level 3 reports, csp-violation ones only, at most twenty', function (): void {
    $bodies = array_fill(0, 22, []);
    $json = json_decode(Reports::level3($bodies), true);
    $json[3]['type'] = 'deprecation';
    $reports = ($this->read)('application/reports+json', (string) json_encode($json));

    expect($reports)->toHaveCount(20)
        ->and($reports[0]->directive)->toBe('img-src');

    $few = json_decode(Reports::level3(array_fill(0, 5, [])), true);
    $few[1]['type'] = 'network-error';

    expect(($this->read)('application/reports+json', (string) json_encode($few)))->toHaveCount(4);
});

it('reads either shape sent as application/json, and nothing of another type', function (): void {
    expect(($this->read)('application/json', Reports::level2()))->toHaveCount(1)
        ->and(($this->read)('application/json', Reports::level3([[], []])))->toHaveCount(2)
        ->and(($this->read)('text/plain', Reports::level2()))->toBe([])
        ->and(($this->read)(null, Reports::level2()))->toBe([])
        ->and(($this->read)('application/reports+json', Reports::level2()))->toBe([]);
});

it('reads a type sent with parameters or in capitals', function (): void {
    expect(($this->read)('application/csp-report; charset=utf-8', Reports::level2()))->toHaveCount(1)
        ->and(($this->read)('Application/Reports+JSON', Reports::level3()))->toHaveCount(1);
});

it('keeps only an address’s origin, never its path or query', function (): void {
    $reports = ($this->read)('application/csp-report', Reports::level2(['blocked-uri' => 'https://cdn.example.org:8443/a/b.js?token=secret#x']));
    $socket = ($this->read)('application/csp-report', Reports::level2(['effective-directive' => 'connect-src', 'blocked-uri' => 'wss://live.example.org/socket?k=1']));
    $data = ($this->read)('application/csp-report', Reports::level2(['blocked-uri' => 'data']));

    expect($reports[0]->blocked)->toBe('https://cdn.example.org:8443')
        ->and($socket[0]->blocked)->toBe('wss://live.example.org')
        ->and($data[0]->blocked)->toBe('data:');

    $blob = ($this->read)('application/csp-report', Reports::level2(['blocked-uri' => 'blob:https://ledger.example/uuid']));
    expect($blob[0]->blocked)->toBe('blob:');
});

it('reads an empty or inline blocked-uri as inline, and keeps eval', function (): void {
    foreach (['', 'inline'] as $blocked) {
        expect(($this->read)('application/csp-report', Reports::level2(['effective-directive' => 'script-src-elem', 'blocked-uri' => $blocked]))[0]->blocked)->toBe('inline');
    }

    foreach (['eval', 'wasm-eval', 'trusted-types-policy', 'trusted-types-sink'] as $keyword) {
        expect(($this->read)('application/csp-report', Reports::level2(['effective-directive' => 'script-src', 'blocked-uri' => $keyword]))[0]->blocked)->toBe($keyword);
    }
});

it('drops a report from an extension, blocked or as its source', function (string $scheme): void {
    expect(($this->read)('application/csp-report', Reports::level2(['blocked-uri' => $scheme.'//abc/x.js'])))->toBe([])
        ->and(($this->read)('application/csp-report', Reports::level2(['effective-directive' => 'script-src-elem', 'blocked-uri' => 'inline', 'source-file' => $scheme.'//abc/x.js'])))->toBe([]);
})->with(['chrome-extension:', 'moz-extension:', 'safari-extension:', 'safari-web-extension:']);

it('drops a report about another site’s page', function (): void {
    expect(($this->read)('application/csp-report', Reports::level2(['document-uri' => 'https://other.example/about/'])))->toBe([])
        ->and(($this->read)('application/csp-report', Reports::level2(['document-uri' => 'http://ledger.example/about/'])))->toBe([])
        ->and(($this->read)('application/csp-report', Reports::level2(['document-uri' => 'not a url'])))->toBe([]);
});

it('drops a report whose directive Teme doesn’t know', function (): void {
    expect(($this->read)('application/csp-report', Reports::level2(['effective-directive' => 'made-up-src'])))->toBe([])
        ->and(($this->read)('application/csp-report', Reports::level2(['effective-directive' => null, 'violated-directive' => 'font-src ‘self’']))[0]->directive)->toBe('font-src');
});

it('reads the disposition as given, else as a report', function (): void {
    expect(($this->read)('application/csp-report', Reports::level2(['disposition' => 'enforce']))[0]->disposition)->toBe('enforce')
        ->and(($this->read)('application/csp-report', Reports::level2(['disposition' => 'nonsense']))[0]->disposition)->toBe('report');
});

it('keeps a sample of 40 characters at most, with its source’s path and no query, for inline and eval only', function (): void {
    $inline = ($this->read)('application/csp-report', Reports::level2([
        'effective-directive' => 'script-src-elem', 'blocked-uri' => 'inline', 'script-sample' => str_repeat('a', 60),
        'source-file' => 'https://ledger.example/about/?q=secret', 'line-number' => 7, 'column-number' => 3,
    ]));

    expect($inline[0]->sample)->toBe(['sample' => str_repeat('a', 40), 'source' => 'https://ledger.example/about/', 'line' => 7, 'column' => 3]);

    $image = ($this->read)('application/csp-report', Reports::level2(['script-sample' => 'x', 'source-file' => 'https://ledger.example/a']));
    $bare = ($this->read)('application/csp-report', Reports::level2(['effective-directive' => 'script-src', 'blocked-uri' => 'eval', 'script-sample' => 'eval(1)']));

    expect($image[0]->sample)->toBeNull()
        ->and($bare[0]->sample)->toBe(['sample' => 'eval(1)']);
});

it('reads nothing from a body that isn’t JSON, or isn’t an object or a list', function (): void {
    foreach (['not json', '', '"text"', '42', 'null', '{"other": 1}', '[1, 2]'] as $body) {
        expect(($this->read)('application/json', $body))->toBe([]);
    }
});

it('drops a whole report that holds a control, format, private-use or unassigned character, or invalid UTF-8', function (array $body): void {
    $inline = ['effective-directive' => 'script-src-elem', 'blocked-uri' => 'inline'];

    expect(($this->read)('application/csp-report', Reports::level2([...$inline, ...$body])))->toBe([]);
})->with([
    'a NUL in the sample' => [['script-sample' => "\0"]],
    'a NUL in the page path' => [['document-uri' => 'https://ledger.example/%00']],
    'an ESC in the page path' => [['document-uri' => 'https://ledger.example/a%1Bb']],
    'a line break in the sample' => [['script-sample' => "a\nb"]],
    'a zero-width space in the sample' => [['script-sample' => "a\u{200B}b"]],
    'a private-use character in the sample' => [['script-sample' => "a\u{E000}b"]],
    'an unassigned character in the sample' => [['script-sample' => "a\u{0378}b"]],
    'invalid UTF-8 in the path' => [['document-uri' => 'https://ledger.example/a%FFb']],
    'a control character in the source' => [['source-file' => "https://ledger.example/a\x01.js"]],
    'a control character in the blocked address' => [['blocked-uri' => "https://cdn.example.org/a\x07", 'effective-directive' => 'script-src-elem']],
]);

it('keeps an ordinary non-ASCII sample and path', function (): void {
    $reports = ($this->read)('application/csp-report', Reports::level2([
        'effective-directive' => 'script-src-elem', 'blocked-uri' => 'inline', 'script-sample' => 'café ünïcode 日本',
        'document-uri' => 'https://ledger.example/caf%C3%A9/',
    ]));

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->path)->toBe('/café')
        ->and($reports[0]->sample)->toBe(['sample' => 'café ünïcode 日本']);
});
