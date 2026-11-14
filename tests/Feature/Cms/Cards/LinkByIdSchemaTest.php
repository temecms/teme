<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('accepts a link by ID to an article, a category or tag, an author or a file, with a place at most, and refuses any other', function (string $href, bool $accepted): void {
    $check = fn () => docValidate(docWith(guardLink($href, 'words')));

    $accepted ? expect($check)->not->toThrow(Throwable::class) : expect($check)->toThrow(ValidationException::class);
})->with([
    'an article' => ['teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r', true],
    'a category, at a place' => ['teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#latest', true],
    'an author' => ['teme:user/42', true],
    'a file, at a page' => ['teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T#page=3', true],
    'a term’s ID in capitals' => ['teme:term/01K5Z9P2QH7N3X4C8V6B0M1T2S', false],
    'a file’s ID in small letters' => ['teme:media/01k5z9p2qh7n3x4c8v6b0m1t2t', false],
    'a person numbered 0' => ['teme:user/0', false],
    'a person’s ID with a leading 0' => ['teme:user/042', false],
    'a person’s ID past the largest' => ['teme:user/1234567890123456789', false],
    'a place with no name' => ['teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#', false],
    'a query' => ['teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T?download=1', false],
    'a kind no link may have' => ['teme:widget/01k5z9p2qh7n3x4c8v6b0m1t2s', false],
]);

it('repairs a link by ID’s case and place, or removes the link and keeps its words', function (string $href, ?string $kept): void {
    $text = DocumentNormaliser::normalise(docWith(guardLink($href, 'the words')))['content'][0]['content'][0];

    expect($text['text'])->toBe('the words')
        ->and($text['marks'][0]['attrs']['href'] ?? null)->toBe($kept);
})->with([
    'a term’s link in capitals' => ['TEME:Term/01K5Z9P2QH7N3X4C8V6B0M1T2S', 'teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s'],
    'a file’s ID in small letters, at a place with a space' => ['teme:media/01k5z9p2qh7n3x4c8v6b0m1t2t#Page 3', 'teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T#Page%203'],
    'an author, at a place with no name' => ['teme:user/42#', 'teme:user/42'],
    'spaces around a link by ID' => ['  teme:user/42  ', 'teme:user/42'],
    'an article, unchanged' => ['teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times', 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times'],
    'a person numbered 0' => ['teme:user/0', null],
    'a kind no link may have' => ['teme:widget/42', null],
    'no ID' => ['teme:term/', null],
    'a query' => ['teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T?download=1', null],
]);

it('reads what a link by ID leads to, and keeps internalLink() to articles alone', function (): void {
    expect(DocumentSchema::linkTarget('teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#latest'))->toBe(['kind' => 'term', 'id' => '01k5z9p2qh7n3x4c8v6b0m1t2s', 'place' => '#latest'])
        ->and(DocumentSchema::linkTarget('teme:user/42'))->toBe(['kind' => 'user', 'id' => '42', 'place' => ''])
        ->and(DocumentSchema::linkTarget('teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T'))->toBe(['kind' => 'media', 'id' => '01K5Z9P2QH7N3X4C8V6B0M1T2T', 'place' => ''])
        ->and(DocumentSchema::linkTarget('teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times'))->toBe(['kind' => 'content', 'id' => '01k5z9p2qh7n3x4c8v6b0m1t2r', 'place' => '#times'])
        ->and(DocumentSchema::linkTarget('/category/news/'))->toBeNull()
        ->and(DocumentSchema::internalLink('teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s'))->toBeNull()
        ->and(DocumentSchema::internalLink('teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#times'))->toBe(['id' => '01k5z9p2qh7n3x4c8v6b0m1t2r', 'place' => '#times']);
});

it('writes a link by ID that it then reads back, for the link box and the importer', function (): void {
    expect(DocumentSchema::linkTo('term', '01k5z9p2qh7n3x4c8v6b0m1t2s', '#latest'))->toBe('teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#latest')
        ->and(DocumentSchema::linkTarget(DocumentSchema::linkTo('media', '01K5Z9P2QH7N3X4C8V6B0M1T2T')))->toBe(['kind' => 'media', 'id' => '01K5Z9P2QH7N3X4C8V6B0M1T2T', 'place' => '']);
});

it('stores a link by ID through the Operation, repaired', function (): void {
    $this->seed(RoleSeeder::class);
    $editor = User::factory()->create()->assignRole('editor');

    $stored = docSave(lifecycleDraft($editor, 'Weekend guide'), $editor, docWith(guardLink('teme:TERM/01K5Z9P2QH7N3X4C8V6B0M1T2S#Latest', 'the harbour')))->workingRevision->document;

    expect($stored['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe('teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#Latest');
});

it('names every kind of link when it refuses an address', function (): void {
    expect(fn () => docValidate(docWith(guardLink('teme:widget/1', 'words'))))->toThrow(function (InvalidDocumentException $refusal): void {
        expect($refusal->errors()['document'][0])->toBe('A link can go to an item, a category or tag, an author or a file on this site, a page on this site (/…), a place on a page (#…), or a web, email or phone address.')
            ->and($refusal->path)->toEndWith('attrs.href');
    });
});
