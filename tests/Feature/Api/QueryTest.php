<?php

declare(strict_types=1);

use App\Domain\Api\ApiQuery;
use App\Domain\Api\InvalidParameters;
use Illuminate\Http\Request;

/**
 * The query as the API reads it from a raw query string.
 *
 * @param  list<string>  $allowed
 */
function queryOf(string $query, array $allowed): ApiQuery
{
    return ApiQuery::from(Request::create('/api/v1/items?'.$query), $allowed);
}

/**
 * Each parameter the query refuses, with why.
 *
 * @param  list<string>  $allowed
 *
 * @return array<string, string>
 */
function queryRefused(string $query, array $allowed): array
{
    try {
        queryOf($query, $allowed);
    } catch (InvalidParameters $refused) {
        return $refused->problems;
    }

    return [];
}

it('takes the parameters its route takes', function (): void {
    $query = queryOf('type=movie%2Cpost&limit=2&after=abc', ['type', 'limit', 'after', 'term']);

    expect($query->values)->toBe(['type' => 'movie,post', 'limit' => '2', 'after' => 'abc'])
        ->and($query->get('type'))->toBe('movie,post')
        ->and($query->get('term'))->toBeNull()
        ->and(queryOf('', ['type'])->values)->toBe([]);
});

it('refuses an unknown parameter, a repeated one and a list, naming each', function (): void {
    $problems = queryRefused('type=a&type=b&tpye=x&term[]=y', ['type', 'term']);

    expect($problems)->toHaveCount(3)
        ->and($problems)->toBe([
            'type' => 'type can be given once, as plain text.',
            'tpye' => 'tpye isn’t a parameter this address takes.',
            'term[]' => 'term can be given once, as plain text.',
        ])
        ->and(queryRefused('colour=red', ['type']))->toBe(['colour' => 'colour isn’t a parameter this address takes.'])
        ->and(queryRefused('type%5B%5D=a', ['type']))->toBe(['type[]' => 'type can be given once, as plain text.']);
});

it('reads an empty value as empty, never as missing', function (): void {
    $query = queryOf('type=&slug', ['type', 'slug']);

    expect($query->get('type'))->toBe('')
        ->and($query->get('slug'))->toBe('')
        ->and($query->values)->toBe(['type' => '', 'slug' => '']);
});

it('takes limit from 1 to 100 and refuses 0, 101, 1.5, -1, 07 and text', function (): void {
    expect(ApiQuery::limit(null))->toBe(20)
        ->and(ApiQuery::limit('1'))->toBe(1)
        ->and(ApiQuery::limit('100'))->toBe(100)
        ->and(ApiQuery::limit('37'))->toBe(37);

    foreach (['0', '101', '1.5', '-1', '07', 'ten', '', ' 5', '1e2'] as $given) {
        expect(fn () => ApiQuery::limit($given))->toThrow(function (InvalidParameters $refused) use ($given): void {
            expect($refused->problems)->toBe(['limit' => 'limit must be a whole number from 1 to 100.'], "limit={$given}");
        });
    }
});

it('refuses a name or a value that isn\'t plain text, naming it so the answer always encodes', function (string $query, string $named, string $detail): void {
    $problems = queryRefused($query, ['type']);

    expect(array_keys($problems))->toBe([$named])
        ->and($problems[$named])->toBe($detail)
        ->and(json_encode($problems, JSON_THROW_ON_ERROR))->toBeString();
})->with([
    'a name that isn\'t UTF-8' => ['%FF=1', '?', '? isn’t a parameter this address takes.'],
    'a NUL in a name' => ['ty%00pe=1', 'type', 'type isn’t a parameter this address takes.'],
    'a value that isn\'t UTF-8' => ['type=%FFmovie', 'type', 'type must be plain text, with no control characters.'],
    'a NUL in a value' => ['type=movie%00', 'type', 'type must be plain text, with no control characters.'],
    'DEL in a value' => ['type=movie%7F', 'type', 'type must be plain text, with no control characters.'],
    'a very long name' => [str_repeat('x', 5000).'=1', str_repeat('x', 64), str_repeat('x', 64).' isn’t a parameter this address takes.'],
]);
