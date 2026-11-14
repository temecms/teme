<?php

declare(strict_types=1);

use App\Domain\Api\Cursor;

it('reads back the position it wrote', function (): void {
    $written = Cursor::after('-published_at', '2026-11-09T08:00:00Z', '01jcb7y1r9n3w4m5p6q7s8t9v0');
    $read = Cursor::read($written, '-published_at');

    expect($written)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(base64_decode(strtr($written, '-_', '+/'), true))->toBe('["-published_at","2026-11-09T08:00:00Z","01jcb7y1r9n3w4m5p6q7s8t9v0"]')
        ->and($read?->sort)->toBe('-published_at')
        ->and($read?->key)->toBe('2026-11-09T08:00:00Z')
        ->and($read?->id)->toBe('01jcb7y1r9n3w4m5p6q7s8t9v0')
        ->and(Cursor::read(Cursor::after('name', 'Science fiction', '01jcaa0b1c2d3e4f5g6h7j8k9m'), 'name')?->key)->toBe('Science fiction');
});

it('refuses a cursor of another sort, of the wrong shape, too long, or with an ID that isn\'t one', function (string $cursor): void {
    expect(Cursor::read($cursor, '-published_at'))->toBeNull();
})->with([
    'another sort' => fn () => Cursor::after('published_at', '2026-11-09T08:00:00Z', '01jcb7y1r9n3w4m5p6q7s8t9v0'),
    'an upper-case ID' => fn () => Cursor::after('-published_at', '2026-11-09T08:00:00Z', '01JCB7Y1R9N3W4M5P6Q7S8T9V0'),
    'an ID with a u' => fn () => Cursor::after('-published_at', '2026-11-09T08:00:00Z', '01jcb7y1r9n3w4m5p6q7s8t9vu'),
    'a short ID' => fn () => Cursor::after('-published_at', '2026-11-09T08:00:00Z', '01jcb7'),
    'two parts' => fn () => rtrim(strtr(base64_encode('["-published_at","01jcb7y1r9n3w4m5p6q7s8t9v0"]'), '+/', '-_'), '='),
    'four parts' => fn () => rtrim(strtr(base64_encode('["-published_at","a","01jcb7y1r9n3w4m5p6q7s8t9v0","x"]'), '+/', '-_'), '='),
    'an object' => fn () => rtrim(strtr(base64_encode('{"0":"-published_at","1":"a","2":"01jcb7y1r9n3w4m5p6q7s8t9v0"}'), '+/', '-_'), '='),
    'a number for a key' => fn () => rtrim(strtr(base64_encode('["-published_at",5,"01jcb7y1r9n3w4m5p6q7s8t9v0"]'), '+/', '-_'), '='),
    'standard base64' => fn () => base64_encode('["-published_at","2026-11-09T08:00:00Z","01jcb7y1r9n3w4m5p6q7s8t9v0"]').'+/',
    'padding' => fn () => Cursor::after('-published_at', 'x', '01jcb7y1r9n3w4m5p6q7s8t9v0').'==',
    'too long' => fn () => Cursor::after('-published_at', str_repeat('k', 800), '01jcb7y1r9n3w4m5p6q7s8t9v0'),
    'not JSON' => fn () => rtrim(strtr(base64_encode('not json'), '+/', '-_'), '='),
]);

it('never throws on any string', function (string $junk): void {
    expect(fn () => Cursor::read($junk, '-published_at'))->not->toThrow(Throwable::class)
        ->and(Cursor::read($junk, '-published_at'))->toBeNull();
})->with([
    '', ' ', '-', '_', '=', 'a', '!!!', '%00', "\0", 'null', 'W10', 'WyJ9', 'e30', 'bnVsbA', 'WzEsMiwzXQ',
    'Wy0xXQ', str_repeat('A', 1025), 'WyJ4IiwieCIsIngiXQ', '😀', 'WyIt cHVibGlzaGVkX2F0Il0',
]);
