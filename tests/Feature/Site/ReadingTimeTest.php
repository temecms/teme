<?php

declare(strict_types=1);

use App\Domain\Site\ReadingTime;

it('rounds reading time up, and never below a minute', function (int $words, int $minutes): void {
    expect(ReadingTime::minutes($words))->toBe($minutes);
})->with([[0, 1], [100, 1], [238, 1], [239, 2], [2380, 10]]);
