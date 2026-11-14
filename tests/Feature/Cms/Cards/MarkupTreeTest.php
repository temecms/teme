<?php

declare(strict_types=1);

use App\Domain\Cards\Imported\MarkupTree;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tree = icFixture()['content'][0]['attrs']['markup'];
});

it('accepts the fixture\'s tree, and every element on the list in its place (I-329)', function (): void {
    $everything = [
        icEl('h2', [icText('Two')]), icEl('h3', [icText('Three')]), icEl('h4', [icText('Four')]),
        icEl('blockquote', [icEl('p', [icText('Said so.')])]),
        icEl('pre', [icEl('code', [icText("line one\n  line two")])]),
        icEl('ol', [icEl('li', [icText('Third'), icEl('ul', [icEl('li', [icText('Nested')])])])], ['start' => 3, 'type' => 'i']),
        icEl('table', [icEl('tfoot', [icEl('tr', [icEl('th', [icText('Total')], ['scope' => 'row', 'rowspan' => 2])])])]),
        icEl('p', [icText('A picture '), icEl('img', [], ['mediaId' => '01K6A0D1000000000000000MD1', 'alt' => 'The quay']), icEl('a', [icEl('strong', [icText('to the article')])], ['href' => 'teme:content/'.strtolower((string) Str::ulid())])]),
    ];

    expect(MarkupTree::problem($this->tree))->toBeNull()
        ->and(MarkupTree::problem($everything))->toBeNull();
});

it('refuses a tree off the list, saying where and why (I-329)', function (string $case): void {
    $cell = fn (array $attrs): array => [icEl('table', [icEl('tbody', [icEl('tr', [icEl('td', [icText('x')], $attrs)])])])];

    [$tree, $words] = match ($case) {
        'an element off the list' => [[icEl('script', [icText('steal()')])], '0: [script] isn’t an element this card may hold.'],
        'an element out of its place' => [[icEl('p', [icEl('li', [icText('x')])])], '0.children.0: [p] can’t hold [li].'],
        'text where only rows go' => [[icEl('table', [icText('x')])], '0.children.0: [table] can’t hold text.'],
        'a row at the top' => [[icEl('tr')], '0: the card can’t hold [tr].'],
        'an attribute off the list' => [[icEl('p', [icText('x')], ['onclick' => 'steal()'])], '0: [p] can’t carry [onclick].'],
        'a script for an address' => [[icEl('a', [icText('x')], ['href' => 'javascript:steal()'])], '0: [a]’s [href] isn’t a value it accepts.'],
        'a link in a link' => [[icEl('a', [icEl('a', [icText('x')], ['href' => 'https://example.org/b'])], ['href' => 'https://example.org/a'])], '0.children.0: a link can’t hold another link.'],
        'a cell spanning no columns' => [$cell(['colspan' => 0]), '0.children.0.children.0.children.0: [td]’s [colspan] isn’t a value it accepts.'],
        'a picture without its file' => [[icEl('img', [], ['alt' => 'The quay'])], '0: [img] needs its [mediaId].'],
        'a line break holding words' => [[icEl('br', [icText('x')])], '0: [br] holds nothing.'],
        'a part that is both' => [[['text' => 'x', 'element' => 'p']], '0: a part is an element or a run of text.'],
        default => [[['text' => 12]], '0: a part is an element or a run of text.'],
    };

    expect(MarkupTree::problem($tree))->toBe($words);
})->with([
    'an element off the list', 'an element out of its place', 'text where only rows go', 'a row at the top', 'an attribute off the list',
    'a script for an address', 'a link in a link', 'a cell spanning no columns', 'a picture without its file', 'a line break holding words',
    'a part that is both', 'text that isn\'t a string',
]);

it('refuses more than 2,000 parts, and parts deeper than 12 levels (I-329)', function (): void {
    $nested = function (int $levels): array {
        $tree = [icText('deep')];

        for ($level = 0; $level < $levels; $level++) {
            $tree = [icEl('blockquote', $tree)];
        }

        return $tree;
    };

    expect(MarkupTree::problem(array_fill(0, 2000, icText('a'))))->toBeNull()
        ->and(MarkupTree::problem(array_fill(0, 2001, icText('a'))))->toBe('It holds more than 2,000 parts.')
        ->and(MarkupTree::problem($nested(11)))->toBeNull()
        ->and(MarkupTree::problem($nested(12)))->toBe('0'.str_repeat('.children.0', 12).': it nests deeper than 12 levels.');
});

it('reads its words: blocks apart, inline parts together, a picture by its alt text (I-329)', function (): void {
    expect(MarkupTree::text($this->tree))->toBe('High water, this week Day Time Monday 6:12am No sailings on Tuesday & Wednesday Ebb The tide going out. See the tide tables. H2O, always cold. Mind the steps.')
        ->and(MarkupTree::text([icEl('p', [icText('At the'), icEl('img', [], ['mediaId' => '01K6A0D1000000000000000MD1', 'alt' => 'quay'])])]))->toBe('At the quay')
        ->and(MarkupTree::text('not a tree'))->toBe('');
});

it('says what it mostly holds, a table first (I-329)', function (string $holds): void {
    $tree = match ($holds) {
        'table' => [icEl('ul', [icEl('li', [icText('x')])]), icEl('table', [icEl('tbody', [icEl('tr', [icEl('td')])])])],
        'definition-list' => [icEl('dl', [icEl('dt', [icText('x')])]), icEl('ol', [icEl('li', [icText('x')])])],
        'list' => [icEl('p', [icText('x')]), icEl('ol', [icEl('li', [icText('x')])])],
        default => [icEl('p', [icText('x')])],
    };

    expect(MarkupTree::holds($tree))->toBe($holds);
})->with(['table', 'definition-list', 'list', 'text']);

it('finds each library picture and each article it links to, once each (I-329)', function (): void {
    $article = strtolower((string) Str::ulid());
    $picture = (string) Str::ulid();
    $tree = [
        icEl('p', [icEl('img', [], ['mediaId' => $picture]), icEl('a', [icText('one')], ['href' => "teme:content/{$article}"])]),
        icEl('p', [icEl('img', [], ['mediaId' => $picture]), icEl('a', [icText('again')], ['href' => "teme:content/{$article}#tides"]), icEl('a', [icText('away')], ['href' => 'https://example.org/'])]),
    ];

    expect(array_map(fn ($reference): string => "{$reference->kind}:{$reference->id}", MarkupTree::references($tree)))
        ->toBe(["media:{$picture}", "content:{$article}"]);
});
