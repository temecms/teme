<?php

declare(strict_types=1);

use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Cards\BlockIds;
use App\Domain\Cards\DocumentDiff;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Cards\PlainText;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * One block of each kind that may carry an id, with this id on it.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function blockWithId(?string $id): array
{
    $text = fn (): array => ['type' => 'text', 'text' => 'Hello'];
    $paragraph = fn (): array => ['type' => 'paragraph', 'content' => [$text()]];
    $cell = ['type' => 'tableCell', 'content' => [$paragraph()]];

    return [
        'paragraph' => ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [$text()]],
        'blockquote' => ['type' => 'blockquote', 'attrs' => ['id' => $id], 'content' => [$paragraph()]],
        'codeBlock' => ['type' => 'codeBlock', 'attrs' => ['id' => $id], 'content' => [$text()]],
        'listItem' => ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'attrs' => ['id' => $id], 'content' => [$paragraph()]]]],
        'taskItem' => ['type' => 'taskList', 'content' => [['type' => 'taskItem', 'attrs' => ['id' => $id, 'checked' => false], 'content' => [$paragraph()]]]],
        'table' => ['type' => 'table', 'attrs' => ['id' => $id], 'content' => [['type' => 'tableRow', 'content' => [$cell]]]],
    ];
}

/**
 * The node that carries the id, wherever the block puts it.
 *
 * @param  array<string, mixed>  $block
 *
 * @return array<string, mixed>
 */
function blockIdNode(string $kind, array $block): array
{
    return in_array($kind, ['listItem', 'taskItem'], true) ? $block['content'][0] : $block;
}

dataset('block kinds that carry an id', ['paragraph', 'blockquote', 'codeBlock', 'listItem', 'taskItem', 'table']);

it('accepts a valid ULID id on a :dataset block', function (string $kind): void {
    docValidate(docWith(blockWithId('01K5Z9P2QH7N3X4C8V6B0M1T3D')[$kind]));
})->with('block kinds that carry an id')->throwsNoExceptions();

it('still refuses an id on a list, a table row or a table cell', function (array $node): void {
    expect(fn () => docValidate(docWith($node)))->toThrow(InvalidDocumentException::class);
})->with([
    'bulletList' => [['type' => 'bulletList', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [['type' => 'listItem', 'content' => [docText()]]]]],
    'tableCell' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [docText()]]]]]]],
    'tableRow' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [['type' => 'tableCell', 'content' => [docText()]]]]]]],
]);

it('refuses an id of another shape on a :dataset block until it is normalised', function (string $kind): void {
    $document = docWith(blockWithId('9b2c6d1e-4f3a-4c1b-8e2d-7a6b5c4d3e2f')[$kind]);

    expect(fn () => docValidate($document))->toThrow(InvalidDocumentException::class);

    $clean = DocumentNormaliser::normalise($document);

    expect(blockIdNode($kind, $clean['content'][0])['attrs']['id'])->toBeNull();
    docValidate($clean);
})->with('block kinds that carry an id');

it('puts a lowercase ULID on a :dataset block in upper case', function (string $kind): void {
    $clean = DocumentNormaliser::normalise(docWith(blockWithId('01k5z9p2qh7n3x4c8v6b0m1t3d')[$kind]));

    expect(blockIdNode($kind, $clean['content'][0])['attrs']['id'])->toBe('01K5Z9P2QH7N3X4C8V6B0M1T3D');
})->with('block kinds that carry an id');

it('keeps the first of a repeated id and nulls the later ones', function (array $nodes, array $expected): void {
    $clean = DocumentNormaliser::normalise(docWith(...$nodes));

    expect(array_map(fn (array $node): mixed => $node['attrs']['id'], $clean['content']))->toBe($expected);
})->with(function (): array {
    $id = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $paragraph = fn (string $id): array => ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => 'A']]];
    $heading = fn (string $id): array => ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => $id], 'content' => [['type' => 'text', 'text' => 'H']]];
    $card = fn (string $id): array => ['type' => 'callout', 'attrs' => ['id' => $id, 'tone' => 'note', 'text' => 'Hi']];

    return [
        'two paragraphs' => [[$paragraph($id), $paragraph($id)], [$id, null]],
        'a paragraph and a heading' => [[$paragraph($id), $heading($id)], [$id, null]],
        'a heading, a paragraph and a card' => [[$heading($id), $paragraph($id), $card($id)], [$id, null, $id]],
    ];
});

it('treats a repeat in another case as a repeat', function (): void {
    $clean = DocumentNormaliser::normalise(docWith(
        ['type' => 'paragraph', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [['type' => 'text', 'text' => 'A']]],
        ['type' => 'paragraph', 'attrs' => ['id' => '01k5z9p2qh7n3x4c8v6b0m1t3d'], 'content' => [['type' => 'text', 'text' => 'B']]],
    ));

    expect($clean['content'][0]['attrs']['id'])->toBe('01K5Z9P2QH7N3X4C8V6B0M1T3D')
        ->and($clean['content'][1]['attrs']['id'])->toBeNull();
});

it('finds a repeat nested inside another block', function (): void {
    $id = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $clean = DocumentNormaliser::normalise(docWith(
        ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => 'A']]],
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => 'B']]]]],
    ));

    expect($clean['content'][1]['content'][0]['attrs']['id'])->toBeNull();
});

it('leaves a document with no ids byte for byte as it was', function (): void {
    $document = docWith(
        docText('One'),
        ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => null], 'content' => [['type' => 'text', 'text' => 'H']]],
        ['type' => 'blockquote', 'content' => [docText('Q')]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [docText('I')]]]],
    );

    expect(json_encode(DocumentNormaliser::normalise($document)))->toBe(json_encode($document));
});

it('leaves a document of distinct valid ids as it was', function (): void {
    $document = docWith(
        ['type' => 'paragraph', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [['type' => 'text', 'text' => 'A']]],
        ['type' => 'paragraph', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3E'], 'content' => [['type' => 'text', 'text' => 'B']]],
    );

    expect(json_encode(DocumentNormaliser::normalise($document)))->toBe(json_encode($document));
});

it('renders a paragraph with an id exactly as it renders one without', function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Storage::fake('media');
    Storage::fake('media-originals');
    User::factory()->create()->assignRole('author');

    $plain = docWith(docText('Hello'));
    $withId = docWith(['type' => 'paragraph', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [['type' => 'text', 'text' => 'Hello']]]);

    expect(app(DocumentRenderer::class)->html($withId))->toBe(app(DocumentRenderer::class)->html($plain));
});

it('reads a paragraph with an id the same as one without, for search, the AI rewrite and the compare', function (): void {
    $plain = docWith(docText('Hello world'));
    $withId = docWith(['type' => 'paragraph', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'], 'content' => [['type' => 'text', 'text' => 'Hello world']]]);

    expect(PlainText::of($withId))->toBe(PlainText::of($plain))
        ->and(TextBlocks::hash($withId['content'][0]))->toBe(TextBlocks::hash($plain['content'][0]))
        ->and(TextBlocks::sameKind($withId['content'][0], $withId['content'][0]))->toBeTrue()
        ->and(app(DocumentDiff::class)->between($plain, $withId))->toEqual(app(DocumentDiff::class)->between($plain, $plain));
});

it('does not call a block changed because it gained ids, nested ones included', function (): void {
    $list = fn (?string $id): array => ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'attrs' => ['id' => $id], 'content' => [docText('One')]]]];
    $without = docWith(['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [docText('One')]]]]);
    $with = docWith($list('01K5Z9P2QH7N3X4C8V6B0M1T3D'));

    expect(array_map(fn ($block): string => $block->kind, app(DocumentDiff::class)->between($without, $with)))->toBe(['same']);
});

it('never changes a card\'s id, even when it repeats, and still counts it as seen', function (array $nodes, array $expected): void {
    $clean = DocumentNormaliser::normalise(docWith(...$nodes));

    expect(array_map(fn (array $node): mixed => $node['attrs']['id'], $clean['content']))->toBe($expected);
})->with(function (): array {
    $id = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $paragraph = fn (string $id): array => ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => 'A']]];
    $card = fn (string $id): array => ['type' => 'callout', 'attrs' => ['id' => $id, 'tone' => 'note', 'text' => 'Hi']];

    return [
        'two cards' => [[$card($id), $card($id)], [$id, $id]],
        'a card, then a paragraph with its id' => [[$card($id), $paragraph($id)], [$id, null]],
        'two cards, then a paragraph' => [[$card($id), $card($id), $paragraph($id)], [$id, $id, null]],
    ];
});

it('saves a document with two callouts that share an id, keeping both', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $content = lifecycleDraft($author, 'Harbour festival');
    $callout = ['type' => 'callout', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D', 'tone' => 'note', 'text' => 'Tickets in July.']];

    $stored = docSave($content, $author, docWith($callout, $callout))->workingRevision->document;

    expect($stored['content'][0]['attrs']['id'])->toBe('01K5Z9P2QH7N3X4C8V6B0M1T3D')
        ->and($stored['content'][1]['attrs']['id'])->toBe('01K5Z9P2QH7N3X4C8V6B0M1T3D');
});

it('lists the blocks that carry an id exactly as the schema lets them', function (): void {
    expect(BlockIds::types())->toEqualCanonicalizing(['heading', 'paragraph', 'blockquote', 'codeBlock', 'listItem', 'taskItem', 'table']);
});

it('gives a :dataset block without an id a new one, and its inner paragraphs theirs', function (string $kind): void {
    $made = 0;
    $newId = function () use (&$made): string {
        $made++;

        return sprintf('01K5Z9P2QH7N3X4C8V6B0M%04d', $made);
    };

    [$filled, $rule] = BlockIds::fill([blockWithId(null)[$kind], ['type' => 'horizontalRule']], $newId);

    expect(blockIdNode($kind, $filled)['attrs']['id'])->toBe('01K5Z9P2QH7N3X4C8V6B0M0001')
        ->and($rule)->toBe(['type' => 'horizontalRule'])
        ->and(json_encode($filled))->not->toContain('"id":null');
})->with('block kinds that carry an id');

it('keeps a valid id, replaces one of another shape, and gives none to a list, a row, a cell or a card', function (): void {
    $kept = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $card = ['type' => 'callout', 'attrs' => ['id' => 'not-a-ulid', 'tone' => 'note', 'text' => 'Hi']];
    $nodes = [
        ['type' => 'paragraph', 'attrs' => ['id' => $kept], 'content' => [['type' => 'text', 'text' => 'A']]],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => '9b2c6d1e-4f3a-4c1b-8e2d-7a6b5c4d3e2f'], 'content' => [['type' => 'text', 'text' => 'H']]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'attrs' => ['id' => $kept], 'content' => [docText('I')]]]],
        $card,
    ];

    $filled = BlockIds::fill($nodes, fn (): string => '01K5Z9P2QH7N3X4C8V6B0M0001');

    expect($filled[0])->toBe($nodes[0])
        ->and($filled[1]['attrs'])->toBe(['level' => 2, 'id' => '01K5Z9P2QH7N3X4C8V6B0M0001'])
        ->and($filled[2])->not->toHaveKey('attrs')
        ->and($filled[2]['content'][0]['attrs']['id'])->toBe($kept)
        ->and($filled[2]['content'][0]['content'][0]['attrs']['id'])->toBe('01K5Z9P2QH7N3X4C8V6B0M0001')
        ->and($filled[3])->toBe($card);
});

it('renders a :dataset block with an id exactly as it renders one without', function (string $kind): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Storage::fake('media');
    Storage::fake('media-originals');
    User::factory()->create()->assignRole('author');

    expect(app(DocumentRenderer::class)->html(docWith(blockWithId('01K5Z9P2QH7N3X4C8V6B0M1T3D')[$kind])))
        ->toBe(app(DocumentRenderer::class)->html(docWith(blockWithId(null)[$kind])));
})->with('block kinds that carry an id');
