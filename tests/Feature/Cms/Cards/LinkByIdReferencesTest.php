<?php

declare(strict_types=1);

use App\Domain\Cards\CardReference;
use App\Domain\Cards\Imported\MarkupTree;
use App\Domain\Cards\References;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Queries\MediaUsageList;
use App\Domain\Sitemaps\Queries\SitemapImages;
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
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('reports what each link by ID leads to, each once, in document order, beside the cards’ references', function (): void {
    $term = '01k5z9p2qh7n3x4c8v6b0m1t2s';
    $file = '01K5Z9P2QH7N3X4C8V6B0M1T2T';
    $image = '01K5Z9P2QH7N3X4C8V6B0M1T2A';
    $article = '01k5z9p2qh7n3x4c8v6b0m1t2r';
    $node = '01K6A0D1000000000000000SM1';

    $document = docWith(
        lkLink("teme:term/{$term}#latest", 'the harbour'),
        ['type' => 'image', 'attrs' => ['id' => $node, 'mediaId' => $image]],
        lkLink('teme:user/42', 'Ada'),
        lkLink("teme:media/{$file}#page=2", 'the report'),
        lkLink("teme:term/{$term}", 'the harbour again'),
        lkLink("teme:media/{$file}", 'the report again'),
        lkLink("teme:content/{$article}#times", 'the times'),
        lkLink('/category/news/', 'a path'),
    );

    expect(array_map(fn (CardReference $reference): string => "{$reference->kind}:{$reference->id}", References::of($document)))
        ->toBe(["term:{$term}", "media:{$image}", 'user:42', "media:{$file}", "content:{$article}"])
        ->and(References::contentIds($document))->toBe([$article])
        ->and(References::mediaUses($document))->toBe(["node:{$node}:{$image}" => $image, "link:{$file}" => $file]);
});

it('turns a link by ID into a linked reference, and any other address into none', function (): void {
    expect(References::link('teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s#latest'))->toEqual(new CardReference('term', '01k5z9p2qh7n3x4c8v6b0m1t2s', linked: true))
        ->and(References::link('teme:user/42'))->toEqual(CardReference::linkTo('user', '42'))
        ->and(References::link('teme:media/01K5Z9P2QH7N3X4C8V6B0M1T2T'))->toEqual(CardReference::linkTo('media', '01K5Z9P2QH7N3X4C8V6B0M1T2T'))
        ->and(References::link('teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r'))->toEqual(CardReference::linkTo('content', '01k5z9p2qh7n3x4c8v6b0m1t2r'))
        ->and(References::link('teme:term/01K5Z9P2QH7N3X4C8V6B0M1T2S'))->toBeNull()
        ->and(References::link('/category/news/'))->toBeNull()
        ->and(CardReference::media('01K5Z9P2QH7N3X4C8V6B0M1T2T')->linked)->toBeFalse();
});

it('records a link to a file as a use of the file, once however many links lead to it, and clears it when they go', function (): void {
    $file = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');
    $content = lifecycleDraft($this->editor, 'Annual meeting');

    docSave($content, $this->editor, docWith(lkLink("teme:media/{$file->id}", 'the report'), lkLink("teme:media/{$file->id}#page=4", 'its accounts')));

    expect(DB::table('media_usages')->where('content_id', $content->id)->pluck('ref')->all())->toBe(["link:{$file->id}"])
        ->and(refsUses($file))->toBe([1, 0])
        ->and(array_column(app(MediaUsageList::class)->page($file->id, null)['rows'], 'id'))->toBe([$content->id]);

    docSave($content, $this->editor, docWith(docText('No report now.')));

    expect(refsUses($file))->toBe([0, 0]);
});

it('counts a published article’s link to a file as a published use', function (): void {
    $file = mediaUpload($this->editor, mediaPdf(), 'Annual report.pdf');

    pageArticleFromDocument($this->editor, 'Annual meeting', [lkLink("teme:media/{$file->id}", 'the report')]);

    expect(refsUses($file))->toBe([1, 1]);
});

it('leaves a linked image out of the article’s image sitemap, which names the images it shows', function (): void {
    $shown = lkImage($this->editor, 'Quay.jpg');
    $linked = lkImage($this->editor, 'Boats.jpg');

    $article = pageArticleFromDocument($this->editor, 'Harbour pictures', [
        pageImageNode($shown, '01K6A0D1000000000000000SM1'),
        lkLink("teme:media/{$linked->id}", 'the boats, larger'),
    ]);

    expect(refsUses($linked))->toBe([1, 1])
        ->and(app(SitemapImages::class)->of([$article->id]))->toBe([$article->id => [url(MediaDisks::public()->url("{$shown->id}/v1/harbour-w1280.webp"))]]);
});

it('records a file an Imported content card links to as a link, not as a picture the card shows, and leaves it out of the image sitemap', function (): void {
    $shown = lkImage($this->editor, 'Quay.jpg');
    $linked = lkImage($this->editor, 'Boats.jpg');
    $card = icNode([icEl('p', [
        icEl('img', [], ['mediaId' => $shown->id, 'alt' => 'The quay']),
        icEl('a', [icText('the boats, larger')], ['href' => "teme:media/{$linked->id}"]),
        icEl('a', [icText('the harbour')], ['href' => 'teme:term/01k5z9p2qh7n3x4c8v6b0m1t2s']),
    ])]);

    $article = pageArticleFromDocument($this->editor, 'Harbour pictures', [$card]);

    expect(array_map(fn (CardReference $reference): string => "{$reference->kind}:{$reference->id}", MarkupTree::references($card['attrs']['markup'])))
        ->toBe(["media:{$shown->id}", "media:{$linked->id}", 'term:01k5z9p2qh7n3x4c8v6b0m1t2s'])
        ->and(DB::table('media_usages')->where('content_id', $article->id)->where('scope', 'published')->orderBy('ref')->pluck('ref')->all())
        ->toBe(["link:{$linked->id}", "node:{$card['attrs']['id']}:{$shown->id}"])
        ->and(app(SitemapImages::class)->of([$article->id]))->toBe([$article->id => [url(MediaDisks::public()->url("{$shown->id}/v1/harbour-w1280.webp"))]]);
});
