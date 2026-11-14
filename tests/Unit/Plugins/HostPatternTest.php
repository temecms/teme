<?php

declare(strict_types=1);

use App\Domain\Plugins\HostPattern;

it('accepts a host name or one leading wildcard, in lower case, and nothing else', function (string $host, bool $ok): void {
    expect(preg_match(HostPattern::PATTERN, $host) === 1)->toBe($ok);
})->with([
    ['api.example.com', true],
    ['*.example.com', true],
    ['a-b.example.co.uk', true],
    ['example.com', true],
    ['localhost', false],
    ['10.0.0.1', false],
    ['[::1]', false],
    ['API.example.com', false],
    ['*.*.example.com', false],
    ['api.*.com', false],
    ['*', false],
    ['-a.example.com', false],
    ["api.example.com\n", false],
]);

it('lets a wildcard cover the hosts and wildcards below it, and nothing else', function (string $granted, string $asked, bool $covers): void {
    expect(HostPattern::covers($granted, $asked))->toBe($covers)
        ->and(HostPattern::anyCovers(['other.example.org', $granted], $asked))->toBe($covers);
})->with([
    ['api.example.com', 'api.example.com', true],
    ['*.example.com', 'api.example.com', true],
    ['*.example.com', 'a.b.example.com', true],
    ['*.example.com', '*.api.example.com', true],
    ['*.example.com', 'example.com', false],
    ['*.example.com', 'evilexample.com', false],
    ['api.example.com', '*.example.com', false],
    ['api.example.com', 'www.example.com', false],
]);
