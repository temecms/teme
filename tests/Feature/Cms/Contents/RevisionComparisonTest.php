<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentDiff;
use App\Domain\Contents\Queries\RevisionComparison;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('shows a paragraph that did not change as unchanged', function (): void {
    $document = docWith(docText('Three days of music by the quay.'), docText('Tickets from Friday.'));

    $blocks = app(DocumentDiff::class)->between($document, $document);

    expect(array_map(fn ($block): string => $block->kind, $blocks))->toBe(['same', 'same'])
        ->and($blocks[0]->node)->toBe('paragraph')
        ->and($blocks[0]->after)->toBe('Three days of music by the quay.');
});

it('marks an added paragraph and a removed one', function (): void {
    $two = docWith(docText('Three days of music.'), docText('Tickets from Friday.'));
    $three = docWith(docText('Three days of music.'), docText('Tickets from Friday.'), docText('Bring a coat.'));

    $added = app(DocumentDiff::class)->between($two, $three);
    $removed = app(DocumentDiff::class)->between($three, $two);

    expect(array_map(fn ($block): string => $block->kind, $added))->toBe(['same', 'same', 'added'])
        ->and($added[2]->before)->toBeNull()
        ->and($added[2]->after)->toBe('Bring a coat.')
        ->and(array_map(fn ($block): string => $block->kind, $removed))->toBe(['same', 'same', 'removed'])
        ->and($removed[2]->before)->toBe('Bring a coat.')
        ->and($removed[2]->after)->toBeNull();
});

it('shows a changed paragraph with the words that differ', function (): void {
    $before = docWith(docText('Three days of music by the quay.'));
    $after = docWith(docText('Four days of music by the harbour.'));

    $blocks = app(DocumentDiff::class)->between($before, $after);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]->kind)->toBe('changed')
        ->and($blocks[0]->after)->toContain('<ins>Four</ins>')
        ->and($blocks[0]->after)->toContain('<del>Three</del>');
});

it('keeps a space between a removed word and the word that replaced it, so the words never run together (#531)', function (): void {
    $before = docWith(docText('Serve it hot with dough on the quay.'));
    $after = docWith(docText('Serve it four, with coffee on the harbour.'));

    $after = app(DocumentDiff::class)->between($before, $after)[0]->after;
    $read = html_entity_decode(strip_tags((string) $after));

    expect($read)->toBe('Serve it hot four, with dough coffee on the quay. harbour.')
        ->and($after)->not->toContain('</del><ins>')
        ->and($after)->not->toContain('</ins><del>');
});

it('marks each side with its own words, the removed in the before and the added in the after, and counts them', function (): void {
    $blocks = app(DocumentDiff::class)->between(
        docWith(docText('Tickets are hot today.')),
        docWith(docText('Tickets are four pounds today.'), docText('Bring a coat.')),
    );

    expect($blocks[0]->markedBefore)->toBe('Tickets are <del>hot</del> today.')
        ->and($blocks[0]->markedAfter)->toBe('Tickets are <ins>four pounds</ins> today.')
        ->and([$blocks[0]->added, $blocks[0]->removed])->toBe([2, 1])
        ->and($blocks[0]->label)->toBeNull()
        ->and($blocks[1]->kind)->toBe('added')
        ->and([$blocks[1]->added, $blocks[1]->removed])->toBe([3, 0]);
});

it('escapes the text it marks up', function (): void {
    $blocks = app(DocumentDiff::class)->between(
        docWith(docText('Plain.')),
        docWith(docText('<script>alert(1)</script>')),
    );

    expect($blocks[0]->after)->not->toContain('<script>')
        ->and($blocks[0]->after)->toContain('&lt;script&gt;');
});

it('describes a card that changed without pretending its attributes are prose', function (): void {
    $id = (string) Str::ulid();
    $mediaId = (string) Str::ulid();
    $before = docWith(histCard('image', ['id' => $id, 'mediaId' => $mediaId, 'alt' => 'The quay at dusk', 'caption' => null]));
    $after = docWith(histCard('image', ['id' => $id, 'mediaId' => $mediaId, 'alt' => 'The quay at night', 'caption' => null]));

    $blocks = app(DocumentDiff::class)->between($before, $after);

    // "Image — alt text changed", not a word diff of a JSON blob.
    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]->kind)->toBe('changed')
        ->and($blocks[0]->node)->toBe('image')
        ->and($blocks[0]->after)->toBe('Image — alt text changed')
        ->and($blocks[0]->label)->toBe('Image')
        ->and($blocks[0]->after)->not->toContain('mediaId');
});

it('describes a card that moved as one block, not a removal and an addition', function (): void {
    $callout = histCard('callout', ['id' => (string) Str::ulid(), 'tone' => 'note', 'text' => 'Gates open at six.']);
    $before = docWith(docText('Three days of music.'), docText('Tickets from Friday.'), $callout);
    $after = docWith($callout, docText('Three days of music.'), docText('Tickets from Friday.'));

    $blocks = app(DocumentDiff::class)->between($before, $after);

    expect(array_map(fn ($block): string => $block->kind, $blocks))->toBe(['moved', 'same', 'same'])
        ->and($blocks[0]->node)->toBe('callout');
});

it('compares the working revision with the published one by default', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music by the quay.')));
    listsPublish($content->refresh(), $this->author);
    $published = $content->refresh()->published_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('Four days of music by the quay.')));
    $working = $content->refresh()->working_revision_id;

    $this->actingAs($this->author)
        ->get(route('content.compare', $content->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/compare')
            ->where('from.id', $published)
            ->where('to.id', $working)
            ->has('blocks', 1)
            ->where('blocks.0.kind', 'changed')
            ->where('type', ['plural' => 'Posts', 'segment' => 'posts'])
            ->has('versions.rows', 3)
            ->where('versions.rows.0.id', $working)
            ->where('versions.older', null));
});

it('refuses the comparison to someone who may not edit the article, with a 403', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $stranger = User::factory()->create()->assignRole('author');

    $this->actingAs($stranger)->get(route('content.compare', $content->id))->assertForbidden();
});

it('describes each card\'s change in its own words', function (string $type, array $before, array $after, string $line): void {
    $id = (string) Str::ulid();
    $blocks = app(DocumentDiff::class)->between(
        docWith(histCard($type, ['id' => $id, ...$before])),
        docWith(histCard($type, ['id' => $id, ...$after])),
    );

    expect($blocks[0]->after)->toBe(e($line));
})->with([
    'an image made decorative and wider' => ['image', ['mediaId' => 'A', 'decorative' => false, 'width' => 'column'], ['mediaId' => 'A', 'decorative' => true, 'width' => 'wide'], 'Image — marked decorative and width changed'],
    'a gallery with two more images' => ['gallery', ['mediaIds' => ['A', 'B']], ['mediaIds' => ['A', 'B', 'C', 'D']], 'Gallery — 2 images added'],
    'a gallery reordered' => ['gallery', ['mediaIds' => ['A', 'B']], ['mediaIds' => ['B', 'A']], 'Gallery — images reordered'],
    'a callout reworded and retoned' => ['callout', ['tone' => 'note', 'text' => 'One'], ['tone' => 'tip', 'text' => 'Two'], 'Callout — tone changed and text changed'],
    'a button sent elsewhere' => ['button', ['label' => 'Go', 'url' => 'https://a.example'], ['label' => 'Go', 'url' => 'https://b.example'], 'Button — link changed'],
    'code rewritten' => ['snippet', ['language' => 'php', 'code' => 'echo 1;'], ['language' => 'php', 'code' => 'echo 2;'], 'Code — code changed'],
    'an embed replaced' => ['embed', ['url' => 'https://a.example', 'title' => 'A'], ['url' => 'https://b.example', 'title' => 'B'], 'Embed — embed replaced'],
]);

it('lists a changed field and an added category, and not the fields that stayed the same', function (): void {
    $news = taxonomyTerm('category', 'News');
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')), [BuiltInFields::SEO_TITLE => 'Harbour festival', BuiltInFields::EXCERPT => 'Music by the quay.'], []);
    listsPublish($content->refresh(), $this->author);
    docSave($content->refresh(), $this->author, docWith(docText('Three days of music.')), [BuiltInFields::SEO_TITLE => 'Harbour festival 2026', BuiltInFields::EXCERPT => 'Music by the quay.'], [$news->id]);

    $comparison = RevisionComparison::between($content->id, $this->author, null, null);

    expect($comparison['fields'])->toBe([
        ['key' => BuiltInFields::SEO_TITLE, 'label' => 'SEO title', 'before' => 'Harbour festival', 'after' => 'Harbour festival 2026'],
    ])
        ->and($comparison['terms'])->toBe([
            ['taxonomy' => 'category', 'label' => 'Categories', 'added' => ['News'], 'removed' => []],
        ])
        ->and(array_map(fn ($block): string => $block->kind, $comparison['blocks']))->toBe(['same']);
});

it('lists nothing for fields and terms when two revisions hold the same ones', function (): void {
    $news = taxonomyTerm('category', 'News');
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')), [BuiltInFields::SEO_TITLE => 'Harbour festival'], [$news->id]);
    $before = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('Four days of music.')));

    $comparison = RevisionComparison::between($content->id, $this->author, $before, null);

    expect($comparison['fields'])->toBe([])
        ->and($comparison['terms'])->toBe([]);
});

it('shows a field that is no longer registered by its key, rather than failing', function (): void {
    $gone = '01K5Z0F1ED00000000000000X9';
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')));
    $before = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('Three days of music.')));
    $after = $content->refresh()->working_revision_id;

    // What a removed plugin leaves behind: values under a key nobody registers.
    // Written directly, because SaveDraft refuses an unknown field.
    DB::table('content_revisions')->where('id', $before)->update(['fields' => json_encode([$gone => 'Old sponsor'])]);
    DB::table('content_revisions')->where('id', $after)->update(['fields' => json_encode([$gone => 'New sponsor'])]);

    expect(RevisionComparison::between($content->id, $this->author, $before, $after)['fields'])->toBe([
        ['key' => $gone, 'label' => $gone, 'before' => 'Old sponsor', 'after' => 'New sponsor'],
    ]);
});

it('reads a switch as on or off, a new image as replaced, and cuts long text short', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')));
    $before = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('Three days of music.')));
    $after = $content->refresh()->working_revision_id;

    // Written directly: the point is how stored values read, not how they were saved.
    DB::table('content_revisions')->where('id', $before)->update(['fields' => json_encode([
        BuiltInFields::META_DESCRIPTION => 'Short.',
        BuiltInFields::NOINDEX => false,
        BuiltInFields::FEATURED_IMAGE => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => 'Boats', 'decorative' => false],
    ])]);
    DB::table('content_revisions')->where('id', $after)->update(['fields' => json_encode([
        BuiltInFields::META_DESCRIPTION => str_repeat('Three days of music by the quay. ', 10),
        BuiltInFields::NOINDEX => true,
        BuiltInFields::FEATURED_IMAGE => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2B', 'alt' => 'Boats', 'decorative' => false],
    ])]);

    $fields = collect(RevisionComparison::between($content->id, $this->author, $before, $after)['fields'])->keyBy('key');

    expect($fields->keys()->all())->toBe([BuiltInFields::META_DESCRIPTION, BuiltInFields::NOINDEX, BuiltInFields::FEATURED_IMAGE])
        ->and($fields[BuiltInFields::NOINDEX]['before'])->toBe('off')
        ->and($fields[BuiltInFields::NOINDEX]['after'])->toBe('on')
        ->and($fields[BuiltInFields::FEATURED_IMAGE]['before'])->toBeNull()
        ->and($fields[BuiltInFields::FEATURED_IMAGE]['after'])->toBe('image replaced')
        ->and($fields[BuiltInFields::META_DESCRIPTION]['before'])->toBe('Short.')
        ->and($fields[BuiltInFields::META_DESCRIPTION]['after'])->toEndWith('...')
        ->and(mb_strlen($fields[BuiltInFields::META_DESCRIPTION]['after']))->toBeLessThanOrEqual(83);
});

it('shows a reviewer the field and category changes of a submitted update against the published version', function (): void {
    $news = taxonomyTerm('category', 'News');
    $harbour = taxonomyTerm('category', 'Harbour');
    $contributor = User::factory()->create()->assignRole('contributor');
    $editor = User::factory()->create()->assignRole('editor');
    $content = lifecycleDraft($contributor, 'Harbour festival');
    docSave($content, $contributor, docWith(docText('Three days of music.')), [BuiltInFields::SEO_TITLE => 'Harbour festival'], [$harbour->id]);
    listsPublish($content->refresh(), $editor);
    docSave($content->refresh(), $contributor, docWith(docText('Three days of music.')), [BuiltInFields::SEO_TITLE => 'Harbour festival 2026'], [$news->id]);
    $reviewId = schedSubmit($content, $contributor);
    $submitted = DB::table('content_reviews')->where('id', $reviewId)->value('revision_id');

    $this->actingAs($editor)
        ->get(route('content.compare', ['content' => $content->id, 'to' => $submitted]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/compare')
            ->where('from.id', $content->refresh()->published_revision_id)
            ->where('to.id', $submitted)
            ->has('fields', 1)
            ->where('fields.0.label', 'SEO title')
            ->where('fields.0.before', 'Harbour festival')
            ->where('fields.0.after', 'Harbour festival 2026')
            ->has('terms', 1)
            ->where('terms.0.label', 'Categories')
            ->where('terms.0.added', ['News'])
            ->where('terms.0.removed', ['Harbour']));
});

it('draws the field and category changes above the body diff, and links a review to it', function (): void {
    $page = uiComponent('pages/content/compare.tsx');

    expect($page)->toContain('<MetaChanges')
        ->and(strpos($page, '<MetaChanges'))->toBeLessThan(strpos($page, '<DiffView'))
        ->and(uiComponent('components/editor/editor-rail.tsx'))->toContain('Compare with the published version');
});

it('names exactly what changed on an image', function (?array $before, ?array $after, string $expected): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music.')));
    $from = $content->refresh()->working_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('Three days of music.')));
    $to = $content->refresh()->working_revision_id;

    // Written directly, in 1.4b's refsFeatured() shape: the point is how the
    // stored values read, not how they were saved.
    DB::table('content_revisions')->where('id', $from)->update(['fields' => json_encode($before === null ? new stdClass : [BuiltInFields::FEATURED_IMAGE => $before])]);
    DB::table('content_revisions')->where('id', $to)->update(['fields' => json_encode($after === null ? new stdClass : [BuiltInFields::FEATURED_IMAGE => $after])]);

    $field = collect(RevisionComparison::between($content->id, $this->author, $from, $to)['fields'])->firstWhere('key', BuiltInFields::FEATURED_IMAGE);

    expect($field['label'])->toBe('Featured image')
        ->and($field['before'])->toBeNull()
        ->and($field['after'])->toBe($expected);
})->with([
    'the alt text' => [
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => 'Boats', 'decorative' => false],
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => 'Boats at dawn', 'decorative' => false],
        'alt text changed: “Boats” → “Boats at dawn”',
    ],
    'marked decorative' => [
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => null, 'decorative' => false],
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => null, 'decorative' => true],
        'marked decorative',
    ],
    'no longer decorative, and described' => [
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => null, 'decorative' => true],
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => 'Boats', 'decorative' => false],
        'no longer decorative; alt text changed: empty → “Boats”',
    ],
    'another image' => [
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => null, 'decorative' => false],
        ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2B', 'alt' => null, 'decorative' => false],
        'image replaced',
    ],
    'added' => [null, ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => null, 'decorative' => false], 'image added'],
    'removed' => [['id' => '01K5Z9P2QH7N3X4C8V6B0M1T2A', 'alt' => null, 'decorative' => false], null, 'image removed'],
]);
