<?php

declare(strict_types=1);

use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A paragraph, a heading, a list whose item holds a paragraph, and a code block.
    $this->document = docWith(
        docText('The ferry is late.'),
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fares']]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [docText('Check the board.')]]]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
    );
});

it('finds a paragraph or a heading by its path, inside a list too, and nothing else', function (): void {
    expect(TextBlocks::at($this->document, '0'))->toBe(docText('The ferry is late.'))
        ->and(TextBlocks::at($this->document, '1')['type'] ?? null)->toBe('heading')
        ->and(TextBlocks::at($this->document, '2.0.0'))->toBe(docText('Check the board.'))
        ->and(TextBlocks::at($this->document, '2.0'))->toBeNull()
        ->and(TextBlocks::at($this->document, '3'))->toBeNull()
        ->and(TextBlocks::at($this->document, '9'))->toBeNull()
        ->and(TextBlocks::at($this->document, '01'))->toBeNull()
        ->and(TextBlocks::at($this->document, '0.'))->toBeNull()
        ->and(TextBlocks::at($this->document, 'a'))->toBeNull();
});

it('measures a block as the editor does: UTF-16 units, a hard break and any other inline node one each', function (): void {
    $block = ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Café 🚢'],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'ok', 'marks' => [['type' => 'bold']]],
        ['type' => 'inlineThing'],
    ]];

    expect(TextBlocks::text($block))->toBe('Café 🚢'."\n".'ok'.mb_chr(0xFFFC))
        // "Café " 5, the ship 2, the break 1, "ok" 2, the inline node 1.
        ->and(TextBlocks::length(TextBlocks::text($block)))->toBe(11)
        ->and(TextBlocks::text(['type' => 'paragraph']))->toBe('');
});

it('slices and splices by offsets, refusing ones outside the text, reversed, or inside a character', function (): void {
    $text = 'Café 🚢 ferry';

    expect(TextBlocks::slice($text, 5, 7))->toBe('🚢')
        ->and(TextBlocks::slice($text, 8, 13))->toBe('ferry')
        ->and(TextBlocks::splice('The ferry is late.', 4, 9, 'boat'))->toBe('The boat is late.')
        ->and(TextBlocks::splice('Late.', 0, 5, 'On time.'))->toBe('On time.')
        ->and(TextBlocks::slice($text, 6, 8))->toBeNull()
        ->and(TextBlocks::slice($text, 3, 2))->toBeNull()
        ->and(TextBlocks::slice($text, -1, 2))->toBeNull()
        ->and(TextBlocks::splice($text, 0, 99, 'x'))->toBeNull();
});

it('hashes a block by its text alone, so marks don\'t count and words do', function (): void {
    $plain = docText('The ferry is late.');
    $marked = ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'The ferry '],
        ['type' => 'text', 'text' => 'is late.', 'marks' => [['type' => 'italic']]],
    ]];

    expect(TextBlocks::hash($marked))->toBe(TextBlocks::hash($plain))
        ->and(TextBlocks::hash($plain))->toBe(hash('sha256', 'The ferry is late.'))
        ->and(TextBlocks::hash(docText('The ferry is early.')))->not->toBe(TextBlocks::hash($plain));
});

it('tells blocks of the same kind by type and attributes, nulls aside', function (): void {
    $heading = fn (int $level): array => ['type' => 'heading', 'attrs' => ['level' => $level, 'id' => null], 'content' => []];

    expect(TextBlocks::sameKind($heading(2), ['type' => 'heading', 'attrs' => ['level' => 2]]))->toBeTrue()
        ->and(TextBlocks::sameKind(['type' => 'paragraph', 'attrs' => ['textAlign' => null]], ['type' => 'paragraph']))->toBeTrue()
        ->and(TextBlocks::sameKind($heading(2), $heading(3)))->toBeFalse()
        ->and(TextBlocks::sameKind(docText('a'), ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center']]))->toBeFalse()
        ->and(TextBlocks::sameKind(docText('a'), ['type' => 'heading', 'attrs' => ['level' => 2]]))->toBeFalse();
});

it('lets the editor\'s block carry an id the stored one lacks, never a different one or none for one it has (I-3362)', function (): void {
    $id = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    $other = '01K5Z9P2QH7N3X4C8V6B0M1T3E';
    $paragraph = fn (?string $id, ?string $align = null): array => ['type' => 'paragraph', 'attrs' => ['id' => $id, 'textAlign' => $align], 'content' => [['type' => 'text', 'text' => 'a']]];

    expect(TextBlocks::sameKind($paragraph(null), $paragraph($id)))->toBeTrue()
        ->and(TextBlocks::sameKind(docText('a'), $paragraph($id)))->toBeTrue()
        ->and(TextBlocks::sameKind($paragraph($id), $paragraph($id)))->toBeTrue()
        ->and(TextBlocks::sameKind($paragraph($id), $paragraph($other)))->toBeFalse()
        ->and(TextBlocks::sameKind($paragraph($id), $paragraph(null)))->toBeFalse()
        ->and(TextBlocks::sameKind($paragraph(null), $paragraph($id, 'center')))->toBeFalse();
});

it('replaces one block by its path and leaves every other as it was', function (): void {
    $expected = $this->document;
    $expected['content'][2]['content'][0]['content'][0] = docText('Check the app.');

    expect(TextBlocks::replace($this->document, '2.0.0', docText('Check the app.')))->toBe($expected);
});

it('writes a draft saved as the assistant\'s change with the origin ai, and any other save as a person\'s (I-317)', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $item = aiDraft($author, 'The ferry is late.');
    $person = ContentRevision::query()->findOrFail($item->working_revision_id);

    $saved = app(SaveDraft::class)->execute(new SaveDraftInput($item->id, $item->title, docWith(docText('The boat is late.')), (array) $person->fields, $item->working_revision_id, (string) $author->id, (array) $person->terms, kind: 'ai'));
    $assistant = ContentRevision::query()->findOrFail($saved->working_revision_id);

    expect($assistant->only(['kind', 'origin', 'author_id']))->toEqual(['kind' => 'ai', 'origin' => 'ai', 'author_id' => $author->id])
        ->and($person->only(['kind', 'origin']))->toBe(['kind' => 'save', 'origin' => 'human']);
});
