<?php

declare(strict_types=1);

use App\Domain\Cards\References;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
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
});

it('finds every reference in a document, in order', function (): void {
    $refs = References::of(docWith(
        ['type' => 'image', 'attrs' => ['id' => str_repeat('A', 26), 'mediaId' => str_repeat('M', 26)]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.str_repeat('c', 26)]]]]]],
        ['type' => 'button', 'attrs' => ['id' => str_repeat('B', 26), 'label' => 'Go', 'contentId' => str_repeat('c', 26)]],
    ));

    expect(array_map(fn ($r): string => $r->kind.':'.$r->id, $refs))
        ->toBe(['media:'.str_repeat('M', 26), 'content:'.str_repeat('c', 26)]);
});

it('records an image card as a use of that media item', function (): void {
    $image = mediaUpload($this->author, mediaJpeg(400, 300), 'harbour.jpg');
    $content = lifecycleDraft($this->author, 'Pictured');
    $nodeId = str_repeat('A', 26);

    docSave($content, $this->author, docWith(['type' => 'image', 'attrs' => ['id' => $nodeId, 'mediaId' => $image->id]]));

    expect(DB::table('media_usages')->where('content_id', $content->id)->pluck('ref')->all())->toBe(["node:{$nodeId}:{$image->id}"])
        ->and(refsUses($image))->toBe([1, 0]);
});

it('records every image in a gallery', function (): void {
    $first = mediaUpload($this->author, mediaJpeg(400, 300), 'quay.jpg');
    $second = mediaUpload($this->author, mediaJpeg(300, 400), 'boats.jpg');
    $content = lifecycleDraft($this->author, 'Gallery');
    $nodeId = str_repeat('G', 26);

    docSave($content, $this->author, docWith(['type' => 'gallery', 'attrs' => ['id' => $nodeId, 'mediaIds' => [$first->id, $second->id]]]));

    expect(DB::table('media_usages')->where('content_id', $content->id)->pluck('ref')->sort()->values()->all())
        ->toBe(collect(["node:{$nodeId}:{$first->id}", "node:{$nodeId}:{$second->id}"])->sort()->values()->all())
        ->and(refsUses($first))->toBe([1, 0])
        ->and(refsUses($second))->toBe([1, 0]);
});

it('records a card\'s image on a type with no media fields', function (): void {
    typesFresh();
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events'));
    $image = mediaUpload($this->author, mediaJpeg(400, 300), 'harbour.jpg');
    $event = typesDraft($this->author, 'event', 'Quay concert');

    docSave($event, $this->author, docWith(['type' => 'image', 'attrs' => ['id' => str_repeat('A', 26), 'mediaId' => $image->id]]));

    expect(refsUses($image))->toBe([1, 0]);
});

it('records a link from one article to another, and clears it when the link goes', function (): void {
    $target = lifecycleDraft($this->author, 'Night market');
    $content = lifecycleDraft($this->author, 'Festival');
    $link = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'see', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$target->id]]]]]];

    docSave($content, $this->author, docWith($link));
    expect(DB::table('content_links')->where('from_content_id', $content->id)->pluck('to_content_id')->all())->toBe([$target->id]);

    docSave($content, $this->author, docWith(docText('No link now.')));
    expect(DB::table('content_links')->where('from_content_id', $content->id)->count())->toBe(0);
});

it('counts a published use separately from a draft one', function (): void {
    $image = mediaUpload($this->author, mediaJpeg(400, 300), 'harbour.jpg');
    $content = lifecycleDraft($this->author, 'Pictured');
    docSave($content, $this->author, docWith(['type' => 'image', 'attrs' => ['id' => str_repeat('A', 26), 'mediaId' => $image->id]]));
    listsPublish($content->refresh(), $this->author);
    docSave($content->refresh(), $this->author, docWith(docText('The image was dropped.')));

    expect(refsUses($image))->toBe([0, 1]);
});
