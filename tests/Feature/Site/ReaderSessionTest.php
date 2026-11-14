<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\SignedInMarker;
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
    $this->editor = User::factory()->create(['name' => 'Ada Obi'])->assignRole('editor');
    $this->story = pageArticle($this->editor, 'Big story');
});

it('answers a browser that never signed in at once, with no session and no cookie (I-163)', function (): void {
    $response = $this->getJson(route('teme.session', ['path' => '/big-story/']))
        ->assertOk()
        ->assertExactJson(['signedIn' => false, 'edit' => null, 'token' => null, 'name' => null, 'moderate' => null])
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->headers->getCookies())->toBe([]);
});

it('tells a signed-in editor where to edit the post the page shows', function (): void {
    $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/big-story/']))
        ->assertOk()
        ->assertJson(['signedIn' => true, 'edit' => ['href' => route('content.edit', ['content' => $this->story->id]), 'label' => 'Edit post']]);
});

it('tells a signed-in person the name their comments go under, for core\'s comment form (1.8b′, D4)', function (): void {
    $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/big-story/']))
        ->assertOk()
        ->assertJsonPath('name', 'Ada Obi');
});

it('tells a signed-in editor where to look after the comments of the item the page shows: one address for every item, never an ID (1.8c″, D1)', function (): void {
    pageArticle($this->editor, 'Another story');
    $ask = fn (string $path): mixed => $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => $path]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('moderate');

    expect($ask('/big-story/'))->toBe(route('comments.index', [], false))
        ->and($ask('/another-story/'))->toBe($ask('/big-story/'))
        ->and($ask('/big-story/'))->not->toContain((string) $this->story->id);
});

it('says an author looks after the comments of their own post and not another\'s, and a contributor of none, as CommentModeration decides (D1)', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $other = User::factory()->create()->assignRole('author');
    $contributor = User::factory()->create()->assignRole('contributor');
    pageArticle($author, 'Harbour notes');
    $ask = fn (User $reader): mixed => $this->actingAs($reader)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/harbour-notes/']))->assertOk()->json('moderate');

    expect($ask($author))->toBe(route('comments.index', [], false))
        ->and($ask($other))->toBeNull()
        ->and($ask($contributor))->toBeNull();
});

it('counts a later page of an item\'s comments as the item, and leaves the Edit post link as 1.7c built it (D2)', function (): void {
    $ask = fn (string $path): array => $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => $path]))->assertOk()->json();

    expect($ask('/big-story/comments/page/2/')['moderate'])->toBe(route('comments.index', [], false))
        ->and($ask('/big-story/comments/page/2/')['edit'])->toBeNull()
        ->and($ask('/no-such-story/comments/page/2/')['moderate'])->toBeNull();
});

it('gives the chosen home page\'s comments at the site\'s address and at its later pages (D2)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $about = addrPublished($this->editor, 'About us', 'page');
    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $about->id]);
    $ask = fn (string $path): mixed => $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => $path]))->assertOk()->json('moderate');

    expect($ask('/'))->toBe(route('comments.index', [], false))
        ->and($ask('/comments/page/2/'))->toBe(route('comments.index', [], false));
});

it('gives nothing where a page shows no one item: the latest posts, a list, a date, an address with nothing at it (D1)', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    $ask = fn (string $path): mixed => $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => $path]))->assertOk()->json('moderate');

    expect($ask('/'))->toBeNull()
        ->and($ask('/category/news/'))->toBeNull()
        ->and($ask('/'.now()->year.'/'))->toBeNull()
        ->and($ask('/no-such-page/'))->toBeNull();
});

it('gives an author the link for their own post, and none for another\'s, as the editor decides', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $other = User::factory()->create()->assignRole('author');
    pageArticle($author, 'Harbour notes');
    $ask = fn (User $reader) => $this->actingAs($reader)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/harbour-notes/']))->assertOk();

    $ask($author)->assertJsonPath('edit.label', 'Edit post');
    $ask($other)->assertJson(['signedIn' => true, 'edit' => null]);
});

it('gives the chosen home page\'s link at the site\'s address', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $about = addrPublished($this->editor, 'About us', 'page');
    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $about->id]);

    $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/']))
        ->assertJsonPath('edit', ['href' => route('content.edit', ['content' => $about->id]), 'label' => 'Edit page']);
});

it('gives no link where a page shows no one item: the latest posts, a list, a date, an address with nothing at it (D12)', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    $ask = fn (string $path): mixed => $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => $path]))->assertOk()->json('edit');

    expect($ask('/'))->toBeNull()
        ->and($ask('/category/news/'))->toBeNull()
        ->and($ask('/'.now()->year.'/'))->toBeNull()
        ->and($ask('/no-such-page/'))->toBeNull();
});

it('clears a marker the browser still carries after its session ended, so it is given stored pages again', function (): void {
    $this->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/big-story/']))
        ->assertExactJson(['signedIn' => false, 'edit' => null, 'token' => null, 'name' => null, 'moderate' => null])
        ->assertCookieExpired(SignedInMarker::COOKIE);
});

it('sends a suspended account away with no link, as the admin does', function (): void {
    $suspended = User::factory()->create(['suspended_at' => now()])->assignRole('editor');

    $this->actingAs($suspended)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/big-story/']))
        ->assertRedirect(route('login'));
});

it('hands a CSRF token only to a browser that sends no Fetch Metadata: Laravel passes a same-origin POST without one', function (): void {
    $ask = fn (array $headers): mixed => $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/big-story/']), $headers)->assertOk()->json('token');

    expect($ask(['Sec-Fetch-Site' => 'same-origin']))->toBeNull()
        ->and($ask([]))->toBeString()->not->toBe('');
});

it('is never stored, by the page cache or any other', function (): void {
    $folder = cacheOn();

    $this->getJson(route('teme.session', ['path' => '/big-story/']))
        ->assertHeaderMissing('X-Teme-Cache')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->editor)->withCookie(SignedInMarker::COOKIE, '1')->withCredentials()
        ->getJson(route('teme.session', ['path' => '/big-story/']))
        ->assertHeaderMissing('X-Teme-Cache')->assertHeader('Cache-Control', 'no-store, private');

    expect(DB::table('page_cache_tags')->count())->toBe(0);

    cacheOff($folder);
});
