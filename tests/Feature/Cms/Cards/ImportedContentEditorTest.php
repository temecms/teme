<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The editor's own menus, words and Convert, run in Node (tests/Fixtures/imported-content.mjs).
    $this->editor = fn (array $markups): array => guardNode('imported-content.mjs', [
        'manifest' => CardManifest::forBrowser(app(CardRegistry::class)),
        'markups' => $markups,
        'sources' => [['shortcode', 'table'], ['html-block', null], ['block', 'acme/pricing'], ['classic', null], [null, null]],
    ]);
    // A converted paragraph's words, a line break as a newline.
    $this->words = fn (array $node): string => implode('', array_map(fn (array $inline): string => ($inline['type'] ?? '') === 'hardBreak' ? "\n" : (string) ($inline['text'] ?? ''), $node['content'] ?? []));
    $this->fixture = icFixture()['content'][0]['attrs']['markup'];
});

it('is never offered by + Insert or the slash menu, while every other card is (I-1715)', function (): void {
    expect(($this->editor)(['fixture' => $this->fixture])['menu'])
        ->not->toContain('card:imported')
        ->toContain('card:image')
        ->toContain('card:video');
});

it('heads the box with what the card holds and where it came from (I-329, I-1704)', function (): void {
    $out = ($this->editor)([
        'fixture' => $this->fixture,
        'list' => [icEl('ul', [icEl('li', [icText('One')])])],
        'text' => [icEl('p', [icText('Words')])],
    ]);

    expect($out['headlines'])->toBe([
        'fixture' => 'Imported from WordPress: a table Teme couldn’t convert',
        'list' => 'Imported from WordPress: a list Teme couldn’t convert',
        'text' => 'Imported from WordPress: formatted text Teme couldn’t convert',
    ])->and($out['sources'])->toBe([
        'It came from the [table] shortcode, as its plugin drew it on the old site.',
        'It came from an HTML block.',
        'It came from the acme/pricing block, which Teme has no match for.',
        'It came from the classic editor’s HTML.',
        null,
    ]);
});

it('converts to paragraphs in the order read: rows joined, header cells and terms bold, breaks kept (I-329)', function (): void {
    $converted = ($this->editor)(['fixture' => $this->fixture])['converted']['fixture'];

    expect(array_column($converted, 'type'))->toBe(array_fill(0, 7, 'paragraph'))
        ->and(array_map($this->words, $converted))->toBe([
            'High water, this week', 'Day | Time', 'Monday | 6:12am', 'No sailings on Tuesday & Wednesday', 'Ebb',
            'The tide going out. See the tide tables.', "H2O, always cold.\nMind the steps.",
        ])
        ->and($converted[1]['content'][0])->toBe(['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Day'])
        ->and($converted[4]['content'][0]['marks'])->toBe([['type' => 'bold']]);
});

it('keeps superscript, subscript, code and links, and drops code beside another mark (I-329)', function (): void {
    $out = ($this->editor)([
        'fixture' => $this->fixture,
        'code-in-link' => [icEl('p', [icEl('a', [icEl('code', [icText('npm run build')])], ['href' => 'https://example.org/'])])],
    ])['converted'];
    $marks = fn (array $paragraph, string $text): array => array_column(collect($paragraph['content'])->firstWhere('text', $text)['marks'] ?? [], 'type');

    expect($marks($out['fixture'][2], 'am'))->toBe(['superscript'])
        ->and($marks($out['fixture'][6], '2'))->toBe(['subscript'])
        ->and($marks($out['fixture'][6], 'cold'))->toBe(['code'])
        ->and($marks($out['fixture'][5], 'the tide tables'))->toBe(['link'])
        ->and($marks($out['code-in-link'][0], 'npm run build'))->toBe(['link']);
});

it('turns lists into paragraphs that start with a bullet or their number, nested lists included (I-329)', function (): void {
    $converted = ($this->editor)(['lists' => [
        icEl('ul', [icEl('li', [icText('One'), icEl('ul', [icEl('li', [icText('Two')])])])]),
        icEl('ol', [icEl('li', [icEl('p', [icText('Three')])]), icEl('li', [icText('Four')])], ['start' => 3]),
    ]])['converted']['lists'];

    expect(array_map($this->words, $converted))->toBe(['• One', '• Two', '3. Three', '4. Four']);
});

it('places each picture as an Image card where it stood (I-329)', function (): void {
    $converted = ($this->editor)(['picture' => [icEl('p', [icText('Before '), icEl('img', [], ['mediaId' => '01K6A0D1000000000000000MD1', 'alt' => 'The quay']), icText(' after')])]])['converted']['picture'];

    expect(array_column($converted, 'type'))->toBe(['paragraph', 'image', 'paragraph'])
        ->and($converted[1]['attrs'])->toMatchArray(['mediaId' => '01K6A0D1000000000000000MD1', 'alt' => 'The quay', 'decorative' => false, 'width' => 'column'])
        ->and(array_map($this->words, [$converted[0], $converted[2]]))->toBe(['Before', 'after']);
});

it('converts to paragraphs the server accepts, and the guard holds the box\'s own values (I-452)', function (): void {
    $converted = ($this->editor)(['fixture' => $this->fixture])['converted']['fixture'];
    $documents = guardEditor()['documents'];

    expect(fn () => app(DocumentValidator::class)->validate(DocumentNormaliser::normalise(docWith(...$converted))))->not->toThrow(Throwable::class)
        ->and($documents)->toHaveKeys(['imported content converted to paragraphs', 'imported content kept'])
        ->and(fn () => docValidate(DocumentNormaliser::normalise($documents['imported content kept'])))->not->toThrow(Throwable::class);
});
