<?php

declare(strict_types=1);

use App\Domain\Api\DocumentLinks;
use App\Domain\Api\Shapes\AuthorShape;
use App\Domain\Api\Shapes\Dates;
use App\Domain\Api\Shapes\ItemLinkShape;
use App\Domain\Api\Shapes\MediaShape;
use App\Domain\Api\Shapes\TermShape;
use App\Domain\Cards\CardMedia;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'bio' => 'Film editor.'])->assignRole('administrator');
});

/**
 * A library file as CardMedia reads it, with its ID added.
 *
 * @return array<string, mixed>
 */
function shapesMedia(string $id): array
{
    return ['id' => $id, ...app(CardMedia::class)->forIds([$id])[$id]];
}

it('gives an image\'s widest variant and never its original', function (): void {
    $image = mediaUpload($this->admin, mediaJpeg(2400, 1600), 'Shell.jpg');
    $entry = shapesMedia($image->id);
    $shape = MediaShape::of($entry);
    $widths = array_column($shape['sizes'], 'width');
    $sorted = $widths;
    sort($sorted);
    $widest = collect($entry['variants'])->filter(fn (array $variant, string $name): bool => str_starts_with($name, 'w'))->sortByDesc('width')->first();
    $original = (string) DB::table('media')->where('id', $image->id)->value('original_path');

    expect(array_keys($shape))->toEqualCanonicalizing(['id', 'kind', 'mime_type', 'alt', 'decorative', 'width', 'height', 'focal', 'url', 'sizes'])
        ->and($shape['id'])->toBe($image->id)
        ->and($shape['kind'])->toBe('image')
        ->and($shape['mime_type'])->toBe('image/jpeg')
        ->and([$shape['width'], $shape['height']])->toBe([2400, 1600])
        ->and($shape['focal'])->toBe(['x' => 50, 'y' => 50])
        ->and($shape['url'])->toBe($widest['url'])
        ->and($shape['sizes'])->not->toBe([])
        ->and($widths)->toBe($sorted)
        ->and(array_keys($shape['sizes'][0]))->toEqualCanonicalizing(['name', 'width', 'height', 'url'])
        ->and($original)->not->toBe('')
        ->and(json_encode($shape, JSON_UNESCAPED_SLASHES))->not->toContain($original)
        ->not->toContain('originals');
});

it('gives a file that isn\'t an image its own address and no sizes', function (): void {
    $pdf = mediaUpload($this->admin, mediaPdf(), 'Menu.pdf');
    $entry = shapesMedia($pdf->id);
    $shape = MediaShape::of($entry);

    expect(array_keys($shape))->toEqualCanonicalizing(['id', 'kind', 'mime_type', 'alt', 'decorative', 'width', 'height', 'focal', 'url', 'sizes'])
        ->and($shape['kind'])->not->toBe('image')
        ->and($shape['mime_type'])->toBe('application/pdf')
        ->and($shape['url'])->toBe($entry['fileUrl'])->not->toBeNull()
        ->and($shape['sizes'])->toBe([])
        ->and([$shape['width'], $shape['height'], $shape['focal']])->toBe([null, null, null]);
});

it('gives a use\'s alt text and decorative over the library\'s', function (): void {
    $image = mediaUpload($this->admin, mediaJpeg(), 'Shell.jpg');
    $library = [...shapesMedia($image->id), 'alt' => 'The library’s words'];

    expect(MediaShape::of($library)['alt'])->toBe('The library’s words')
        ->and(MediaShape::of($library)['decorative'])->toBeFalse()
        ->and(MediaShape::of($library, 'This use’s words')['alt'])->toBe('This use’s words')
        ->and(MediaShape::of($library, 'This use’s words', true)['decorative'])->toBeTrue()
        ->and(MediaShape::of([...$library, 'alt' => null])['alt'])->toBeNull();
});

it('writes every date in UTC with Z', function (): void {
    $lagos = CarbonImmutable::parse('2026-11-10 10:30:00', 'Africa/Lagos');

    expect(Dates::utc($lagos))->toBe('2026-11-10T09:30:00Z')
        ->and(Dates::utc('2026-11-10T10:30:00+01:00'))->toBe('2026-11-10T09:30:00Z')
        ->and(Dates::utc('2026-11-10 09:30:00.731+00'))->toBe('2026-11-10T09:30:00Z')
        ->and(Dates::FORMAT)->toBe('Y-m-d\TH:i:s\Z');
});

it('gives a byline with no email, role or ID', function (): void {
    $row = DB::table('users')->where('id', $this->admin->id)->first();
    $byline = AuthorShape::of($row, 'author/'.$this->admin->slug);

    expect(array_keys($byline))->toEqualCanonicalizing(['name', 'slug', 'path', 'url', 'bio'])
        ->and($byline)->toBe([
            'name' => 'Ada Obi',
            'slug' => $this->admin->slug,
            'path' => '/author/'.$this->admin->slug.'/',
            'url' => 'https://example.com/author/'.$this->admin->slug.'/',
            'bio' => 'Film editor.',
        ])
        ->and(json_encode($byline))->not->toContain('ada@example.com')->not->toContain('administrator')
        ->and(AuthorShape::of((object) ['name' => 'Bo', 'slug' => 'bo', 'bio' => '  '], null))->toBe(['name' => 'Bo', 'slug' => 'bo', 'path' => null, 'url' => null, 'bio' => null]);
});

it('gives a term on an item, and on its own, with exactly its members', function (): void {
    $news = typesTerm($this->admin, 'category', 'News');
    $row = DB::table('terms')->where('id', $news->id)->first();
    $link = TermShape::link($row, 'category/news');
    $full = TermShape::full($row, 'category/news', 3);

    expect(array_keys($link))->toEqualCanonicalizing(['id', 'taxonomy', 'name', 'slug', 'path', 'url'])
        ->and($link)->toBe(['id' => $news->id, 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news', 'path' => '/category/news/', 'url' => 'https://example.com/category/news/'])
        ->and(array_keys($full))->toEqualCanonicalizing(['id', 'taxonomy', 'name', 'slug', 'path', 'url', 'description', 'parent_id', 'count'])
        ->and([$full['description'], $full['parent_id'], $full['count']])->toBe([null, null, 3])
        ->and(TermShape::link($row, null)['url'])->toBeNull();
});

it('gives a linked item with exactly its id, type, language, title and address', function (): void {
    $link = ItemLinkShape::of('01jcb7y1r9n3w4m5p6q7s8t9v0', 'post', 'en-GB', 'Arrival II', 'arrival-ii');

    expect(array_keys($link))->toEqualCanonicalizing(['id', 'type', 'language', 'title', 'path', 'url'])
        ->and($link)->toBe(['id' => '01jcb7y1r9n3w4m5p6q7s8t9v0', 'type' => 'post', 'language' => 'en-GB', 'title' => 'Arrival II', 'path' => '/arrival-ii/', 'url' => 'https://example.com/arrival-ii/']);
});

it('resolves a link by ID, keeps its place, and gives null for a target with no address', function (): void {
    $live = addrPublished($this->admin, 'Ferry fares');
    $draft = lifecycleDraft($this->admin, 'Unfinished');
    $links = app(DocumentLinks::class);

    expect($links->href('teme:content/'.$live->id))->toBe('https://example.com/ferry-fares/')
        ->and($links->href('teme:content/'.$live->id.'#prices'))->toBe('https://example.com/ferry-fares/#prices')
        ->and($links->url($live->id))->toBe('https://example.com/ferry-fares/')
        ->and($links->href('teme:content/'.$draft->id))->toBeNull()
        ->and($links->url($draft->id))->toBeNull()
        ->and($links->href('teme:content/nonsense'))->toBeNull();
});

it('makes a path on this site absolute and leaves another site\'s address as it is', function (): void {
    $links = app(DocumentLinks::class);

    expect($links->href('/about/'))->toBe('https://example.com/about/')
        ->and($links->href('https://elsewhere.example/page'))->toBe('https://elsewhere.example/page')
        ->and($links->href('mailto:ada@example.com'))->toBe('mailto:ada@example.com')
        ->and($links->href('#top'))->toBe('#top')
        ->and($links->href(''))->toBeNull();
});

it('drops a link the page wouldn\'t draw, even one written past the editor\'s guard', function (string $href): void {
    $item = addrPublished($this->admin, 'Ferry fares');
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Click me', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
    ]]]];
    DB::table('content_revisions')->where('id', $item->published_revision_id)->update(['document' => json_encode($document, JSON_THROW_ON_ERROR)]);

    $stored = (string) json_decode((string) DB::table('content_revisions')->where('id', $item->published_revision_id)->value('document'), true)['content'][0]['content'][0]['marks'][0]['attrs']['href'];
    $html = (string) addrGet('/ferry-fares/')->assertOk()->getContent();

    expect($stored)->toBe($href)
        ->and(app(DocumentLinks::class)->href($stored))->toBeNull()
        ->and($html)->toContain('Click me')
        ->and(strtolower($html))->not->toContain(strtolower($href));
})->with(['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,<b>x</b>', 'vbscript:msgbox(1)']);
