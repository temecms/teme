<?php

declare(strict_types=1);

use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Site\SiteDates;
use App\Domain\StructuredData\Vocabulary;
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
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');
    $this->poster = pageImage($this->author, 1600, 900);
    $variants = [];

    foreach (['thumb' => 300, 'w640' => 640, 'w1280' => 1280, 'w1600' => 1600] as $key => $width) {
        $variants[$key] = ['path' => "{$this->poster->id}/v1/harbour-{$key}.webp", 'width' => $width, 'height' => intdiv($width * 9, 16), 'bytes' => 1000];
    }

    DB::table('media')->where('id', $this->poster->id)->update(['variants' => json_encode($variants)]);
    $this->nodeId = '01K6A0D1000000000000000VN2';
    $this->page = 'http://localhost/harbour-news/';
});

it('describes a video with a poster as a VideoObject at its card\'s own address on the page (I-704)', function (): void {
    headPost($this->author, 'Harbour news', [], [], [avVideoNode($this->video, ['id' => $this->nodeId, 'posterMediaId' => $this->poster->id, 'caption' => 'Dawn over the harbour wall'])]);
    // The site's clock, set after the item so its address is made as any other's.
    lscrTimezone('Europe/Paris');
    $file = app(CardMedia::class)->forIds([$this->video->id])[$this->video->id];

    $node = sdNode(sdGraph('/harbour-news/'), $this->page.'#video-'.$this->nodeId);

    expect($node)->toEqual([
        '@id' => $this->page.'#video-'.$this->nodeId,
        '@type' => 'VideoObject',
        'name' => 'Dawn over the harbour wall',
        'thumbnailUrl' => url("/storage/{$this->poster->id}/v1/harbour-w1280.webp"),
        'uploadDate' => SiteDates::inSite($file['uploadedAt'])->format(DATE_ATOM),
        'contentUrl' => url((string) $file['fileUrl']),
    ])->and((string) $node['uploadDate'])->not->toEndWith('+00:00');
});

it('names the video by its file\'s title when it has no caption (I-704)', function (): void {
    headPost($this->author, 'Harbour news', [], [], [avVideoNode($this->video, ['id' => $this->nodeId, 'posterMediaId' => $this->poster->id])]);

    expect(sdNode(sdGraph('/harbour-news/'), $this->page.'#video-'.$this->nodeId)['name'] ?? null)->toBe('Harbour dawn');
});

it('gives no VideoObject for a video without a poster, and keeps the page\'s own node (I-703, I-704)', function (): void {
    headPost($this->author, 'Harbour news', [], [], [avVideoNode($this->video, ['id' => $this->nodeId, 'caption' => 'Dawn'])]);
    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->page.'#video-'.$this->nodeId))->toBeNull()
        ->and(sdNode($graph, $this->page))->not->toBeNull();
});

it('writes only terms on the committed list on a page with a video (I-706)', function (): void {
    headPost($this->author, 'Harbour news', [], [], [avVideoNode($this->video, ['id' => $this->nodeId, 'posterMediaId' => $this->poster->id])]);
    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->page.'#video-'.$this->nodeId))->not->toBeNull()
        ->and(app(Vocabulary::class)->unknown($graph))->toBe([]);
});

it('reads nothing more for its node once the page\'s body has read its files (I-704)', function (): void {
    $card = app(CardRegistry::class)->find('video');
    $attrs = avVideoNode($this->video, ['id' => $this->nodeId, 'posterMediaId' => $this->poster->id])['attrs'];
    app(CardMedia::class)->forIds([$this->video->id, $this->poster->id]);
    SiteDates::inSite('2026-10-11'); // The page has read the site's clock already.

    expect(dashQueries(fn () => $card?->structuredData($attrs, $this->page)))->toBe([])
        ->and($card?->structuredData($attrs, $this->page))->toHaveCount(1);
});
