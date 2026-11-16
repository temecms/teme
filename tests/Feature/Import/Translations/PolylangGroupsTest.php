<?php

declare(strict_types=1);

use App\Domain\Import\Translations\PolylangGroups;

it('joins each map\'s posts into one group', function (): void {
    expect(PolylangGroups::groups([
        ['id' => '21', 'translations' => ['en' => 21, 'es' => 22, 'pt' => 23]],
        ['id' => '22', 'translations' => ['en' => 21, 'es' => 22, 'pt' => 23]],
        ['id' => '23', 'translations' => ['en' => 21, 'es' => 22, 'pt' => 23]],
    ]))->toBe([['21', '22', '23']]);
});

it('joins two maps that share a post, even when one lost a member', function (): void {
    expect(PolylangGroups::groups([
        ['id' => '25', 'translations' => ['en' => 25, 'hi' => 26]],
        ['id' => '26', 'translations' => ['hi' => 26, 'ja' => 27]],
        ['id' => '27', 'translations' => ['ja' => 27]],
    ]))->toBe([['25', '26', '27']]);
});

it('leaves a post with no translation out of every group', function (): void {
    expect(PolylangGroups::groups([
        ['id' => '24', 'translations' => []],
        ['id' => '28', 'translations' => ['it' => 28]],
        ['id' => '21', 'translations' => ['en' => 21, 'es' => 22]],
    ]))->toBe([['21', '22']]);
});

it('groups posts and terms apart', function (): void {
    $posts = PolylangGroups::groups([['id' => '5', 'translations' => ['en' => 5, 'it' => 6]]]);
    $terms = PolylangGroups::groups([['id' => '5', 'translations' => ['en' => 5, 'it' => 7]]]);

    expect($posts)->toBe([['5', '6']])
        ->and($terms)->toBe([['5', '7']]);
});

it('gives the same groups whatever order the maps come in', function (): void {
    $maps = [
        ['id' => '25', 'translations' => ['en' => 25, 'hi' => 26]],
        ['id' => '41', 'translations' => ['en' => 41, 'es' => 42]],
        ['id' => '27', 'translations' => ['ja' => 27, 'hi' => 26]],
        ['id' => '21', 'translations' => ['en' => 21, 'es' => 22]],
    ];

    expect(PolylangGroups::groups(array_reverse($maps)))->toBe(PolylangGroups::groups($maps))
        ->and(PolylangGroups::groups($maps))->toBe([['21', '22'], ['25', '26', '27'], ['41', '42']]);
});
