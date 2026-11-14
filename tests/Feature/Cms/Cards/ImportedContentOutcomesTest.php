<?php

declare(strict_types=1);

use App\Domain\Cards\Queries\ImportedContentOutcomes;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    // An item as the importer leaves it: its working revision is the import's own (origin `import`).
    $this->imported = function (Content $item, array ...$nodes): void {
        docSave($item, $this->author, docWith(...$nodes));
        DB::table('content_revisions')->where('id', $item->refresh()->working_revision_id)->update(['origin' => 'import']);
    };
    $this->outcomes = fn (Content ...$items): array => app(ImportedContentOutcomes::class)->of(array_map(fn (Content $item): string => $item->id, $items));
    $this->words = fn (int $count, string $stem = 'tide'): string => implode(' ', array_map(fn (int $n): string => "{$stem}{$n}", range(1, $count)));
});

it('counts a card still in the article as waiting, and one marked kept as kept (I-329)', function (): void {
    $item = lifecycleDraft($this->author, 'Tide tables');
    $first = icNode([icEl('p', [icText('First')])], ['id' => '01K6A0D1000000000000000KC1']);
    $second = icNode([icEl('table', [icEl('tbody', [icEl('tr', [icEl('td', [icText('Second')])])])])], ['id' => '01K6A0D1000000000000000KC2', 'sourceKind' => 'shortcode', 'sourceName' => 'table']);
    ($this->imported)($item, $first, $second);
    docSave($item, $this->author, docWith($first, ['type' => 'imported', 'attrs' => [...$second['attrs'], 'kept' => true]]));

    expect(($this->outcomes)($item))->toBe([
        ['contentId' => $item->id, 'cardId' => '01K6A0D1000000000000000KC1', 'holds' => 'text', 'sourceKind' => 'html-block', 'sourceName' => null, 'outcome' => 'waiting'],
        ['contentId' => $item->id, 'cardId' => '01K6A0D1000000000000000KC2', 'holds' => 'table', 'sourceKind' => 'shortcode', 'sourceName' => 'table', 'outcome' => 'kept'],
    ]);
});

it('counts a card that has gone as converted while its words are still in the article, lightly edited too (I-337)', function (): void {
    $item = lifecycleDraft($this->author, 'Tide tables');
    ($this->imported)($item, icNode([icEl('p', [icText(($this->words)(60))])]));
    // One word in sixty changed: 59 of 60 is above 98%.
    docSave($item, $this->author, docWith(docText('• '.str_replace('tide7 ', 'changed ', ($this->words)(60)))));

    expect(array_column(($this->outcomes)($item), 'outcome'))->toBe(['converted']);
});

it('counts a card that has gone as deleted when its words went with it, or most of them (I-337)', function (string $after): void {
    $item = lifecycleDraft($this->author, 'Tide tables');
    ($this->imported)($item, icNode([icEl('p', [icText(($this->words)(60))])]));
    docSave($item, $this->author, docWith(docText($after === 'rewritten' ? ($this->words)(30) : 'Something else entirely.')));

    expect(array_column(($this->outcomes)($item), 'outcome'))->toBe(['deleted']);
})->with(['gone', 'rewritten']);

it('counts a card as deleted though its words sit in another imported card, which isn\'t prose (I-337)', function (): void {
    $item = lifecycleDraft($this->author, 'Tide tables');
    $first = icNode([icEl('p', [icText(($this->words)(60))])], ['id' => '01K6A0D1000000000000000KC1']);
    $second = icNode([icEl('p', [icText(($this->words)(60))])], ['id' => '01K6A0D1000000000000000KC2']);
    ($this->imported)($item, $first, $second);
    docSave($item, $this->author, docWith($second));

    expect(array_column(($this->outcomes)($item), 'outcome'))->toBe(['deleted', 'waiting']);
});

it('counts a card of pictures only by its pictures (I-329)', function (): void {
    $image = pageImage($this->author, 800, 600);
    $card = icNode([icEl('p', [icEl('img', [], ['mediaId' => $image->id])])]);
    $converted = lifecycleDraft($this->author, 'Converted');
    $deleted = lifecycleDraft($this->author, 'Deleted');
    ($this->imported)($converted, $card);
    ($this->imported)($deleted, $card);
    docSave($converted, $this->author, docWith(pageImageNode($image, '01K6A0D1000000000000000MD2')));
    docSave($deleted, $this->author, docWith(docText('No picture now.')));

    expect(array_column(($this->outcomes)($converted), 'outcome'))->toBe(['converted'])
        ->and(array_column(($this->outcomes)($deleted), 'outcome'))->toBe(['deleted']);
});

it('reads the newest imported revision, so a catch-up\'s cards replace the first run\'s (I-345)', function (): void {
    $item = lifecycleDraft($this->author, 'Tide tables');
    ($this->imported)($item, icNode([icEl('p', [icText('First run')])], ['id' => '01K6A0D1000000000000000KC1']));
    $this->travel(1)->minutes();
    ($this->imported)($item, icNode([icEl('p', [icText('Catch-up')])], ['id' => '01K6A0D1000000000000000KC3']));

    expect(array_column(($this->outcomes)($item), 'cardId'))->toBe(['01K6A0D1000000000000000KC3']);
});

it('gives nothing for an item never imported, and reads every item in one statement (I-329)', function (): void {
    $never = lifecycleDraft($this->author, 'Written here');
    docSave($never, $this->author, docWith(docText('Written in Teme.')));
    $one = lifecycleDraft($this->author, 'One');
    $two = lifecycleDraft($this->author, 'Two');
    ($this->imported)($one, icNode([icEl('p', [icText('One')])]));
    ($this->imported)($two, icNode([icEl('p', [icText('Two')])]));

    expect(($this->outcomes)($never))->toBe([])
        ->and(dashQueries(fn () => ($this->outcomes)($never, $one, $two)))->toHaveCount(1)
        ->and(app(ImportedContentOutcomes::class)->of([]))->toBe([]);
});
