<?php

declare(strict_types=1);

use App\Domain\Export\ExportJson;

it('keeps empty objects and empty lists apart, as PostgreSQL returns a document', function (): void {
    // jsonb's text form, spaces and all.
    $stored = '{"type": "doc", "content": [{"type": "paragraph", "attrs": {}, "content": []}]}';

    expect(ExportJson::line(['document' => ExportJson::decode($stored)]))
        ->toBe('{"document":{"type":"doc","content":[{"type":"paragraph","attrs":{},"content":[]}]}}'."\n");
});

it('writes zero fractions, slashes and every script as they are, on one line', function (): void {
    $line = ExportJson::line(['n' => 1.0, 'href' => 'teme:content/01jb2zq8v3k7n4w5x6y7z8a9bc', 'text' => "Ọ̀rọ̀ / ẹ̀kọ́\nnext"]);

    expect($line)->toBe('{"n":1.0,"href":"teme:content/01jb2zq8v3k7n4w5x6y7z8a9bc","text":"Ọ̀rọ̀ / ẹ̀kọ́'.chr(92).'nnext"}'."\n")
        ->and(substr_count($line, "\n"))->toBe(1);
});

it('escapes the line and paragraph separators, so a reader that splits lines on them still sees one line', function (): void {
    $line = ExportJson::line(['text' => 'one'.mb_chr(0x2028).'two'.mb_chr(0x2029).'three']);

    expect($line)->toContain(chr(92).'u2028')
        ->and($line)->toContain(chr(92).'u2029')
        ->and($line)->not->toContain(mb_chr(0x2028))
        ->and($line)->not->toContain(mb_chr(0x2029));
});

it('fails on a value it can’t encode, rather than writing it changed', function (): void {
    expect(fn () => ExportJson::line(['text' => "caf\xE9"]))->toThrow(JsonException::class);
});

it('writes every time in UTC with microseconds, whatever offset it was read with', function (): void {
    expect(ExportJson::time('2026-10-20 14:03:07.123456+00'))->toBe('2026-10-20T14:03:07.123456Z')
        ->and(ExportJson::time('2026-10-20 16:03:07+02'))->toBe('2026-10-20T14:03:07.000000Z')
        ->and(ExportJson::time('2026-10-20 14:03:07'))->toBe('2026-10-20T14:03:07.000000Z')
        ->and(ExportJson::time(null))->toBeNull();
});
