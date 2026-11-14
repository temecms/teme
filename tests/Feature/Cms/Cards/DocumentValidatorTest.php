<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// docWith(), docText(), docValidate() and docSave() live in tests/Pest.php,
// because the tests of 1.5c to 1.5f use them too.

/**
 * A bullet list of this many names, as WordPress's name-list posts hold
 * them: 1 + 3 × $names nodes (the list, then each item's listItem,
 * paragraph and text).
 *
 * @return array<string, mixed>
 */
function dvNames(int $names): array
{
    return ['type' => 'bulletList', 'content' => array_map(
        fn (int $n): array => ['type' => 'listItem', 'content' => [docText("Name {$n}")]],
        range(1, $names),
    )];
}

/**
 * A document whose JSON is exactly this many bytes: paragraphs of plain
 * letters, the last one made up to the size.
 *
 * @return array<string, mixed>
 */
function dvBytes(int $bytes): array
{
    $document = docWith(...[...array_fill(0, 127, docText(str_repeat('a', 8000))), docText('a')]);
    $document['content'][127]['content'][0]['text'] .= str_repeat('a', $bytes - strlen((string) json_encode($document)));

    return $document;
}

it('accepts prose, marks and a card', function (): void {
    docValidate(docWith(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'What\'s on']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bold', 'marks' => [['type' => 'bold']]]]],
        ['type' => 'callout', 'attrs' => ['id' => str_repeat('A', 26), 'tone' => 'note', 'text' => 'Tickets go on sale in July.']],
    ));
})->throwsNoExceptions();

it('accepts the attributes Tiptap 3 writes on prose nodes, and refuses a value outside them', function (): void {
    docValidate(docWith(
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Centred']]],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => null], 'content' => [['type' => 'text', 'text' => 'Plain']]],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3, 'type' => null], 'content' => [['type' => 'listItem', 'content' => [docText('Third')]]]],
    ));

    expect(fn () => docValidate(docWith(['type' => 'paragraph', 'attrs' => ['textAlign' => 'diagonal'], 'content' => [['type' => 'text', 'text' => 'x']]])))
        ->toThrow(ValidationException::class);
});

it('refuses a node type nothing registers', function (): void {
    expect(fn () => docValidate(docWith(['type' => 'iframe', 'attrs' => []])))->toThrow(ValidationException::class);
});

it('refuses a mark nothing registers, and a link to a protocol we do not allow', function (): void {
    expect(fn () => docValidate(docWith(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'blink']]]]])))->toThrow(ValidationException::class)
        ->and(fn () => docValidate(docWith(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]])))->toThrow(ValidationException::class);
});

it('accepts a link exactly as the editor stores it', function (): void {
    // Tiptap 3 writes all five attributes on every link (checked 2026-09-21).
    docValidate(docWith(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see', 'marks' => [['type' => 'link', 'attrs' => [
        'href' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r', 'target' => null, 'rel' => null, 'class' => null, 'title' => null,
    ]]]]]]));
})->throwsNoExceptions();

it('refuses a link by ID that is not exactly an ID its table keeps', function (string $href): void {
    expect(fn () => docValidate(docWith(['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]])))
        ->toThrow(ValidationException::class);
})->with([
    'a path' => 'teme:content/../../x',
    'no ID' => 'teme:content/',
    'something after the ID' => 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r/extra',
    'a file’s ID in small letters, which the normaliser repairs before this' => 'teme:media/01k5z9p2qh7n3x4c8v6b0m1t2r',
    'an upper-case ID, which the normaliser repairs before this' => 'teme:content/01K5Z9P2QH7N3X4C8V6B0M1T2R',
]);

it('refuses a card whose attributes do not fit its rules', function (array $attrs): void {
    expect(fn () => docValidate(docWith(['type' => 'image', 'attrs' => $attrs])))->toThrow(ValidationException::class);
})->with([
    'no media' => [['id' => str_repeat('A', 26), 'alt' => 'x']],
    'alt too long' => [['id' => str_repeat('A', 26), 'mediaId' => str_repeat('B', 26), 'alt' => str_repeat('a', 1001)]],
    'unknown attribute' => [['id' => str_repeat('A', 26), 'mediaId' => str_repeat('B', 26), 'onclick' => 'x']],
    'width not in the list' => [['id' => str_repeat('A', 26), 'mediaId' => str_repeat('B', 26), 'width' => 'enormous']],
]);

it('refuses a button with both a link and an article, or with neither', function (): void {
    $id = str_repeat('A', 26);

    expect(fn () => docValidate(docWith(['type' => 'button', 'attrs' => ['id' => $id, 'label' => 'Go']])))->toThrow(ValidationException::class)
        ->and(fn () => docValidate(docWith(['type' => 'button', 'attrs' => ['id' => $id, 'label' => 'Go', 'url' => 'https://example.com', 'contentId' => str_repeat('c', 26)]])))->toThrow(ValidationException::class);   // lowercase, so only the one-target rule refuses it
});

it('refuses a document that nests too deep', function (): void {
    $deep = docText();
    for ($i = 0; $i < 12; $i++) {
        $deep = ['type' => 'blockquote', 'content' => [$deep]];
    }

    expect(fn () => docValidate(docWith($deep)))->toThrow(ValidationException::class);
});

it('refuses a document that is not a doc at all', function (mixed $document): void {
    expect(fn () => docValidate($document))->toThrow(ValidationException::class);
})->with([
    'wrong root' => [['type' => 'paragraph']],
    'no type' => [['content' => []]],
    'text with no text' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text']]]]]],
]);

it('refuses a document the editor never sent, on save', function (): void {
    // Proves the Operations use the validator, not only the editor.
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');

    expect(fn () => app(CreateDraft::class)->execute(
        new CreateDraftInput('post', 'Bad', docWith(['type' => 'script', 'attrs' => []]), [], (string) $author->id),
    ))->toThrow(ValidationException::class);
});

it('normalises what a paste brings, and stores the normalised document', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $target = lifecycleDraft($author, 'Night market');
    $content = lifecycleDraft($author, 'Pasted');

    $document = docWith(
        ['type' => 'codeBlock', 'attrs' => ['language' => 'Python'], 'content' => [['type' => 'text', 'text' => 'print(1)']]],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'C Sharp!'], 'content' => [['type' => 'text', 'text' => 'x']]],
        ['type' => 'orderedList', 'attrs' => ['start' => 0, 'type' => 'disc'], 'content' => [
            ['type' => 'listItem', 'content' => [docText('One')]],
        ]],
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'diagonal'], 'content' => [['type' => 'text', 'text' => 'x']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see', 'marks' => [['type' => 'link', 'attrs' => [
            'href' => 'teme:content/'.strtoupper($target->id), 'target' => null, 'rel' => null, 'class' => null, 'title' => null,
        ]]]]]],
    );

    $clean = DocumentNormaliser::normalise($document);

    expect($clean['content'][0]['attrs']['language'])->toBe('python')
        ->and($clean['content'][1]['attrs']['language'])->toBeNull()
        ->and($clean['content'][2]['attrs']['start'])->toBe(1)
        ->and($clean['content'][2]['attrs']['type'])->toBeNull()
        ->and($clean['content'][3]['attrs']['textAlign'])->toBeNull()
        ->and($clean['content'][4]['content'][0]['marks'][0]['attrs']['href'])->toBe('teme:content/'.$target->id);

    docValidate($clean);   // the normalised document passes the strict validator

    // The Operation accepts the raw paste and stores the normalised form.
    // jsonb reorders object keys, so compare by value, not by order.
    $stored = docSave($content, $author, $document)->workingRevision->document;

    expect($stored)->toEqual($clean);
});

it('repairs a pasted table cell\'s span and alignment, which only the normaliser may do', function (): void {
    $cell = fn (array $attrs): array => ['type' => 'tableCell', 'attrs' => $attrs, 'content' => [docText('Oysters')]];
    $table = docWith(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        $cell(['colspan' => 0, 'rowspan' => '', 'colwidth' => null, 'align' => 'justify']),
        $cell(['colspan' => 2, 'rowspan' => 1, 'colwidth' => [120, 80], 'align' => 'center']),
        // Past what browsers allow: a colspan like this made prosemirror-tables use 312 MB.
        $cell(['colspan' => 20000000, 'rowspan' => 70000, 'colwidth' => null, 'align' => null]),
        $cell(['colspan' => 1000, 'rowspan' => 65534, 'colwidth' => null, 'align' => 'right']),
    ]]]]);
    $tooWide = docWith(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        $cell(['colspan' => 1001, 'rowspan' => 1, 'colwidth' => null, 'align' => null]),
    ]]]]);

    $row = DocumentNormaliser::normalise($table)['content'][0]['content'][0]['content'];

    expect($row[0]['attrs'])->toBe(['colspan' => 1, 'rowspan' => 1, 'colwidth' => null, 'align' => null])
        ->and($row[1]['attrs'])->toBe(['colspan' => 2, 'rowspan' => 1, 'colwidth' => [120, 80], 'align' => 'center'])
        ->and($row[2]['attrs'])->toBe(['colspan' => 1000, 'rowspan' => 65534, 'colwidth' => null, 'align' => null])
        ->and($row[3]['attrs'])->toBe(['colspan' => 1000, 'rowspan' => 65534, 'colwidth' => null, 'align' => 'right'])
        ->and(fn () => docValidate($table))->toThrow(ValidationException::class)
        ->and(fn () => docValidate($tooWide))->toThrow(ValidationException::class, 'The colspan field must not be greater than 1000.');

    docValidate(DocumentNormaliser::normalise($table));
    docValidate(DocumentNormaliser::normalise($tooWide));
});

it('repairs a heading\'s level and ID, and an ordered list\'s start, from another editor or a paste', function (): void {
    $clean = DocumentNormaliser::normalise(docWith(
        ['type' => 'heading', 'attrs' => ['level' => 1, 'id' => '01k5z9p2qh7n3x4c8v6b0m1t3d'], 'content' => [['type' => 'text', 'text' => 'Harbour festival']]],
        ['type' => 'heading', 'attrs' => ['level' => 6, 'id' => '9b2c6d1e-4f3a-4c1b-8e2d-7a6b5c4d3e2f'], 'content' => [['type' => 'text', 'text' => 'Timetable']]],
        ['type' => 'orderedList', 'attrs' => ['start' => null, 'type' => null], 'content' => [['type' => 'listItem', 'content' => [docText('First')]]]],
        ['type' => 'orderedList', 'attrs' => ['start' => '3', 'type' => null], 'content' => [['type' => 'listItem', 'content' => [docText('Third')]]]],
    ));

    expect($clean['content'][0]['attrs'])->toBe(['level' => 2, 'id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D'])
        ->and($clean['content'][1]['attrs'])->toBe(['level' => 4, 'id' => null])
        ->and($clean['content'][2]['attrs'])->toBe(['start' => 1, 'type' => null])
        ->and($clean['content'][3]['attrs'])->toBe(['start' => 3, 'type' => null]);

    docValidate($clean);
});

it('keeps a heading and a list the editor made exactly as they were', function (): void {
    $made = docWith(
        ['type' => 'heading', 'attrs' => ['level' => 3, 'id' => '01K5Z9P2QH7N3X4C8V6B0M1T3D', 'textAlign' => null], 'content' => [['type' => 'text', 'text' => 'Getting there']]],
        ['type' => 'orderedList', 'attrs' => ['start' => 4, 'type' => 'a'], 'content' => [['type' => 'listItem', 'content' => [docText('Fourth')]]]],
    );

    expect(DocumentNormaliser::normalise($made))->toBe($made);
});

it('saves an article holding a level-1 heading and a list that starts at NaN, through the Operation', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $content = lifecycleDraft($author, 'Harbour festival');

    $stored = docSave($content, $author, docWith(
        ['type' => 'heading', 'attrs' => ['level' => 1, 'id' => null], 'content' => [['type' => 'text', 'text' => 'Getting there']]],
        ['type' => 'orderedList', 'attrs' => ['start' => null, 'type' => null], 'content' => [['type' => 'listItem', 'content' => [docText('By ferry')]]]],
    ))->workingRevision->document;

    expect($stored['content'][0]['attrs']['level'])->toBe(2)
        ->and($stored['content'][1]['attrs']['start'])->toBe(1);
});

it('accepts a name list of 20,000 nodes, past the old 512 KB, and refuses one node more, naming the limit (I-2997)', function (): void {
    $names = dvNames(6666);
    $full = docWith($names, ['type' => 'horizontalRule']);
    $over = docWith($names, ['type' => 'horizontalRule'], ['type' => 'horizontalRule']);

    expect(DocumentSchema::MAX_NODES)->toBe(20000)
        ->and(strlen((string) json_encode($full)))->toBeGreaterThan(524288)
        ->and(fn () => docValidate($full))->not->toThrow(Throwable::class)
        ->and(fn () => docValidate($over))->toThrow(ValidationException::class, 'The document has more than 20,000 nodes.');
});

it('accepts a document of exactly 1 MB, and refuses one byte more, naming the limit (I-2997)', function (): void {
    expect(DocumentSchema::MAX_BYTES)->toBe(1048576)
        ->and(strlen((string) json_encode(dvBytes(1048576))))->toBe(1048576)
        ->and(fn () => docValidate(dvBytes(1048576)))->not->toThrow(Throwable::class)
        ->and(fn () => docValidate(dvBytes(1048577)))->toThrow(ValidationException::class, 'The document is larger than 1 MB.');
});

it('validates and saves a 20,000-node name list through the Operation in reasonable time (I-2997)', function (): void {
    $this->seed(RoleSeeder::class);
    $author = User::factory()->create()->assignRole('author');
    $content = lifecycleDraft($author, 'Male Boston Terrier names');
    $document = docWith(dvNames(6666), ['type' => 'horizontalRule']);

    $started = hrtime(true);
    docValidate($document);
    $validated = (hrtime(true) - $started) / 1e9;

    $started = hrtime(true);
    $stored = docSave($content, $author, $document)->workingRevision->document;
    $saved = (hrtime(true) - $started) / 1e9;

    if (getenv('TEME_TIMINGS') === '1') {
        fwrite(STDERR, sprintf("20,000 nodes: validated in %.2f s, saved in %.2f s\n", $validated, $saved));
    }

    expect(count($stored['content'][0]['content']))->toBe(6666)
        ->and($validated)->toBeLessThan(5.0)
        ->and($saved)->toBeLessThan(30.0);
});
