<?php

declare(strict_types=1);

use App\Domain\Feeds\FeedBuilder;
use App\Domain\Feeds\FeedSubject;
use App\Domain\Feeds\Rss2;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
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

it('gives an imported item’s guid as its feed ID, and Teme’s own to every other item (I-922)', function (): void {
    $imported = pageArticle($this->author, 'Imported story');
    $own = pageArticle($this->author, 'Teme story');
    DB::table('contents')->where('id', $imported->id)->update(['guid' => 'http://old.example/?p=12']);

    $ids = [];

    foreach (app(FeedBuilder::class)->build(FeedSubject::site())->items as $item) {
        $ids[$item->title] = $item->id;
    }

    // Keyed by title: two items published in one second may come in either order.
    expect($ids)->toEqual(['Teme story' => 'teme:content/'.$own->id, 'Imported story' => 'http://old.example/?p=12']);
});

it('writes an imported guid into the feed escaped, never as a link', function (): void {
    $imported = pageArticle($this->author, 'Imported story');
    DB::table('contents')->where('id', $imported->id)->update(['guid' => 'http://old.example/?p=12&preview=1']);

    $xml = app(Rss2::class)->render(app(FeedBuilder::class)->build(FeedSubject::site()));

    expect($xml)->toContain('<guid isPermaLink="false">http://old.example/?p=12&amp;preview=1</guid>')
        ->and($xml)->not->toContain('<link>http://old.example');
});

it('keeps a guid of 255 characters, counted as characters, and refuses a longer one', function (): void {
    $imported = pageArticle($this->author, 'Imported story');
    $long = 'https://old.example/?p='.str_repeat('é', 232);

    DB::table('contents')->where('id', $imported->id)->update(['guid' => $long]);

    expect(mb_strlen($long))->toBe(255)
        ->and(DB::table('contents')->where('id', $imported->id)->value('guid'))->toBe($long)
        ->and(fn () => DB::table('contents')->where('id', $imported->id)->update(['guid' => $long.'é']))->toThrow(QueryException::class);
});

it('names the WordPress type each of Teme’s built-in types takes, and none for a region', function (): void {
    $types = app(ContentTypeRegistry::class);

    expect($types->get('post')->wordpress)->toBe('post')
        ->and($types->get('page')->wordpress)->toBe('page')
        ->and($types->get('region')->wordpress)->toBeNull();
});
