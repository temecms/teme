<?php

declare(strict_types=1);

use App\Domain\Site\Head\TitleTemplate;

it('drops empty and unknown tokens with the separators beside them, and keeps a template\'s own words (I-290)', function (string $template, string $expected): void {
    $tokens = ['title' => 'Harbour', 'site' => 'Ledger', 'tagline' => '', 'page' => '', 'search' => 'ferry  fares'];

    expect(TitleTemplate::fill($template, $tokens, '·'))->toBe($expected);
})->with([
    'no empty token' => ['{title} {sep} {site}', 'Harbour · Ledger'],
    'an empty token between' => ['{title} {sep} {page} {sep} {site}', 'Harbour · Ledger'],
    'an empty token at the end' => ['{site} {sep} {page} {sep} {tagline}', 'Ledger'],
    'an unknown token' => ['{title} {sep} {nope} {sep} {site}', 'Harbour · Ledger'],
    'stray separators' => ['{sep} {title} {sep}{sep} {site} {sep}', 'Harbour · Ledger'],
    'words and spaces' => ['  Search   results for {search} {sep} {site} ', 'Search results for ferry fares · Ledger'],
    'a separator typed by hand' => ['{title} – {site}', 'Harbour – Ledger'],
]);

it('fills each token once, so text holding a token\'s name or markup is left as typed', function (): void {
    expect(TitleTemplate::fill('{title} {sep} {site}', ['title' => 'Rates {site} & <b>bold</b>', 'site' => 'Ledger'], '|'))
        ->toBe('Rates {site} & <b>bold</b> | Ledger');
});

it('asks a token given as a closure only when the template uses it, and joins with a space when the separator is blank', function (): void {
    $asked = 0;
    $term = function () use (&$asked): string {
        $asked++;

        return 'Local';
    };

    expect(TitleTemplate::fill('{title} {sep} {site}', ['title' => 'Harbour', 'site' => 'Ledger', 'term' => $term], ' '))->toBe('Harbour Ledger')
        ->and($asked)->toBe(0)
        ->and(TitleTemplate::fill('{term} {sep} {title}', ['title' => 'Harbour', 'term' => $term], '·'))->toBe('Local · Harbour')
        ->and($asked)->toBe(1);
});
