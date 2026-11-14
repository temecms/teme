<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

it('makes IDs the server accepts, unique and in time order', function (): void {
    if (Process::run(['node', '--version'])->failed()) {
        $this->markTestSkipped('Node is not available here, so the IDs the browser makes cannot be checked. This is a known gap, not a pass.');
    }

    $run = Process::timeout(60)->run(['node', base_path('tests/Fixtures/ulid.mjs')]);
    expect($run->successful())->toBeTrue($run->errorOutput());

    $ids = array_values(array_filter(explode("\n", trim($run->output()))));

    expect($ids)->toHaveCount(500)
        ->and(array_unique($ids))->toHaveCount(500)
        ->and($ids)->each->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    $times = array_map(fn (string $id): string => substr($id, 0, 10), $ids);
    $sorted = $times;
    sort($sorted, SORT_STRING);
    expect($times)->toBe($sorted);
});

it('refuses a card or heading id that is not an uppercase ULID', function (array $node): void {
    expect(fn () => docValidate(docWith($node)))->toThrow(ValidationException::class);
})->with([
    'a lowercase card id' => [['type' => 'callout', 'attrs' => ['id' => '01k5z9p2qh7n3x4c8v6b0m1t2r', 'tone' => 'tip', 'text' => 'Hi']]],
    'a U in a card id' => [['type' => 'callout', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2U', 'tone' => 'tip', 'text' => 'Hi']]],
    'a lowercase heading id' => [['type' => 'heading', 'attrs' => ['level' => 2, 'id' => '01k5z9p2qh7n3x4c8v6b0m1t2r'], 'content' => [['type' => 'text', 'text' => 'Hi']]]],
]);
