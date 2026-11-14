<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\SignedInMarker;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
});

it('owns the document: lang, title with the theme separator, skip link, preload, tokens', function (): void {
    pageArticle($this->author, 'Big story');

    $html = addrGet('/big-story/')->assertOk()->getContent();

    expect($html)
        ->toContain('<html lang="en"')
        ->toContain('<title>Big story – '.e(pageSiteName()).'</title>')
        ->toContain('<a class="teme-skip-link" href="#main">')
        ->toContain('rel="preload" as="font"')
        ->toContain('--teme-accent:#b3261e')
        ->toContain('type="speculationrules"');
});

it('titles the site address with the site name, even when the home page is a page', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $editor = User::factory()->create()->assignRole('editor');
    $about = addrPublished($editor, 'About us', 'page');
    $news = addrPublished($editor, 'News', 'page');
    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $about->id, 'postsPageId' => $news->id]);

    $html = addrGet('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>'.e(pageSiteName()).'</title>')
        ->not->toContain('<title>About us');
});

it('links card styles only for the cards on the page', function (): void {
    pageArticleWithCards($this->author, 'With a button', ['button']);

    $html = addrGet('/with-a-button/')->assertOk()->getContent();

    expect($html)->toContain('data-teme-card="button"')->not->toContain('data-teme-card="gallery"')->not->toContain('data-teme-card="embed"');
});

it('leaves out a card style the theme replaces', function (): void {
    // The good fixture's manifest has "cards": {"replaces": ["callout"]}; the button shows the page links the others.
    pageArticleWithCards($this->author, 'With a callout', ['callout', 'button']);

    expect(addrGet('/with-a-callout/')->assertOk()->getContent())
        ->toContain('data-teme-card="button"')
        ->not->toContain('data-teme-card="callout"');
});

it('omits speculation rules when the theme opts out', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/quiet')]]);
    themeActivate('quiet');
    pageArticle($this->author, 'Quiet one');

    // The skip link shows the shell drew the page.
    expect(addrGet('/quiet-one/')->assertOk()->getContent())
        ->toContain('teme-skip-link')
        ->not->toContain('speculationrules');
});

it('omits speculation rules for a signed-in reader, by the marker cookie alone', function (): void {
    pageArticle($this->author, 'Signed in');

    $request = Request::create('/signed-in/', 'GET', cookies: ['teme_auth' => '1']);
    $html = app(HttpKernel::class)->handle($request)->getContent();

    expect($html)->toContain('teme-skip-link')->not->toContain('speculationrules');
});

it('titles a listing by its subject, with its page number from page 2', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    foreach (range(1, 11) as $i) {
        pageArticle($this->author, "Story {$i}", categories: [$news]);
    }

    expect(addrGet('/category/news/')->assertOk()->getContent())->toContain('<title>News – '.e(pageSiteName()).'</title>')
        ->and(addrGet('/category/news/page/2/')->assertOk()->getContent())->toContain('<title>News – Page 2 of 2 – '.e(pageSiteName()).'</title>');
});

it('keeps exactly one priority image on an article with a featured image and image cards', function (): void {
    $this->app['env'] = 'local';
    $featured = pageImage($this->author, 1600, 900);
    $inline = pageImage($this->author, 1200, 800);
    pageArticleFromDocument($this->author, 'Many pictures', [
        pageImageNode($inline, (string) Str::ulid()),
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Between.']]],
        pageImageNode($inline, (string) Str::ulid()),
    ], featured: $featured);

    $response = addrGet('/many-pictures/')->assertOk();

    expect(substr_count($response->getContent(), 'fetchpriority="high"'))->toBe(1)
        ->and(substr_count($response->getContent(), 'loading="lazy"'))->toBe(2);
    $response->assertHeaderMissing('X-Teme-Warnings');
});

it('styles the skip link itself, hidden until focused, with no theme stylesheet at all', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('broken');

    $html = addrGet('/')->assertOk()->getContent();

    expect($html)->toContain('<a class="teme-skip-link" href="#main">')
        ->toContain('@layer teme.core, teme.plugins, teme.theme;')
        ->toContain('.teme-skip-link:focus')
        ->not->toContain('rel="stylesheet"');
});

it('leaves addresses with a query string out of the prerendering, as WordPress does (I-160)', function (): void {
    pageArticle($this->author, 'Big story');
    $html = addrGet('/big-story/')->assertOk()->getContent();
    preg_match('#<script type="speculationrules">(.+?)</script>#s', $html, $script);
    $rules = json_decode($script[1], true);

    // A search page prerendered as the reader heads for it would use up
    // their search limit (1.6h); WordPress leaves out /*\?(.+) for the same.
    expect($rules['prerender'][0]['where']['and'])->toContain(['not' => ['href_matches' => '/*\?(.+)']]);

    // How the browser's matcher reads the rules, from the site's address.
    $read = guardNode('speculation.mjs', [
        'rules' => $rules,
        'base' => rtrim(url('/'), '/').'/',
        'urls' => ['/news/', '/2026/owls/', '/?s=owl', '/page/2/?s=owl'],
    ]);

    expect($read['patterns']['/*\?(.+)'])->toBe(['/news/' => false, '/2026/owls/' => false, '/?s=owl' => true, '/page/2/?s=owl' => true])
        ->and($read['prerendered'])->toBe(['/news/' => true, '/2026/owls/' => true, '/?s=owl' => false, '/page/2/?s=owl' => false]);
});

it('writes core\'s small script on every page, which asks who is reading only in a browser that signed in (I-163)', function (): void {
    pageArticle($this->author, 'Big story');

    $html = addrGet('/big-story/')->assertOk()->getContent();

    expect($html)->toContain('<script data-teme="session">')
        ->toContain('"teme_auth="')
        ->toContain('\/_teme\/session')
        ->toContain('.teme-edit-link{')
        ->toContain('.teme-edit-room{');
});

it('writes the same script for a reader and for a browser that signed in, so the page stays shared', function (): void {
    pageArticle($this->author, 'Big story');
    $script = fn (string $html): string => preg_match('#<script data-teme="session">(.+?)</script>#s', $html, $match) === 1 ? $match[1] : '';

    $reader = (string) addrGet('/big-story/')->getContent();
    $signedIn = (string) cacheGet('/big-story/', [SignedInMarker::COOKIE => '1'])->getContent();

    expect($script($reader))->not->toBe('')->toBe($script($signedIn));
});

it('keeps the reader\'s answer where core\'s other scripts find it, so a page asks once (1.8b′, D3)', function (): void {
    pageArticle($this->author, 'Big story');

    $html = (string) addrGet('/big-story/')->getContent();
    $session = preg_match('#<script data-teme="session">(.+?)</script>#s', $html, $match) === 1 ? $match[1] : '';

    expect($session)->toContain("document[Symbol.for('teme.reader')] = reader;")
        ->toContain(': Promise.resolve(null);');
});
