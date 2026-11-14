<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Rules\CanonicalChoice;
use App\Domain\Media\Models\Media;
use App\Domain\Seo\Queries\EditorSearchAndSocial;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SocialCards;
use App\Domain\Seo\WholeSite;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    cmSettings(['site_title' => 'The Ledger', 'tagline' => 'News from the harbour'], 'general');

    // What the editor's previews make of a saved item: the session and the seo prop it opens with.
    $this->preview = function (Content $item): array {
        $session = EditorSession::for($item->id, (string) $this->editor->id);

        return guardNode('search-preview.mjs', ['preview' => [
            'title' => $session->title,
            'fields' => (object) $session->fields,
            'document' => $session->document,
            'seo' => app(EditorSearchAndSocial::class)->for($session),
            'taxonomies' => $session->taxonomies,
            'terms' => $session->terms,
        ]]);
    };

    // What the page's head says, as a reader's browser gets it.
    $this->head = function (string $uri): array {
        $dom = headDom($uri);

        return [
            'title' => headValue($dom, '//title'),
            'description' => headValue($dom, '//head/meta[@name="description"]/@content'),
            'socialTitle' => headValue($dom, '//head/meta[@property="og:title"]/@content'),
            'socialDescription' => headValue($dom, '//head/meta[@property="og:description"]/@content'),
            'image' => headValue($dom, '//head/meta[@property="og:image"]/@content'),
            'robots' => headValue($dom, '//head/meta[@name="robots"]/@content'),
        ];
    };
});

it('makes an item\'s search result and social card exactly as its page\'s head does (I-290, I-293)', function (string $case, string $imageFrom): void {
    $news = sdCategory('News');
    $local = sdCategory('Local', $news);
    $sport = sdCategory('Sport');
    $featured = pageImage($this->editor, 1600, 900);
    $social = pageImage($this->editor, 1200, 630);
    $inBody = pageImage($this->editor, 1400, 800);
    $text = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The ferry fares go up in the spring.']]];
    $asFeatured = fn (Media $media): array => ['id' => $media->id, 'alt' => null, 'decorative' => false];

    [$title, $fields, $terms, $nodes, $settings] = match ($case) {
        'its SEO title, word for word' => ['Harbour news', [BuiltInFields::SEO_TITLE => 'Fares & “fees”: <what> {site} costs'], [], [$text], []],
        'the template, its primary category for {term}' => ['Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $local->id], [$sport, $local], [$text], ['title_type_post' => '{title} {sep} {term} {sep} {site}']],
        'the template, an empty {term} and its separator dropped' => ['Harbour news', [], [], [$text], ['title_type_post' => '{title} {sep} {term} {sep} {site}']],
        'a template naming {site} inside the item\'s title' => ['The {site} of the harbour', [], [], [$text], []],
        'the template\'s type, author, date and year' => ['Harbour news', [], [], [$text], ['title_separator' => '|', 'title_type_post' => '{title} {sep} {type} by {author}, {date} {sep} {year}']],
        'its meta description' => ['Harbour news', [BuiltInFields::META_DESCRIPTION => 'What the new fares mean.'], [], [$text], []],
        'its excerpt, with no meta description' => ['Harbour news', [BuiltInFields::EXCERPT => 'A short summary.'], [], [$text], []],
        'its social title and description' => ['Harbour news', [BuiltInFields::SEO_TITLE => 'Ferry fares', BuiltInFields::SOCIAL_TITLE => 'Fares are going up', BuiltInFields::SOCIAL_DESCRIPTION => 'Here is what changes.'], [], [$text], []],
        'its social image over its featured image' => ['Harbour news', [BuiltInFields::SOCIAL_IMAGE => $asFeatured($social), BuiltInFields::FEATURED_IMAGE => $asFeatured($featured)], [], [$text], []],
        'its featured image, with no social image' => ['Harbour news', [BuiltInFields::FEATURED_IMAGE => $asFeatured($featured)], [], [$text], []],
        'the first image in its body' => ['Harbour news', [], [], [pageImageNode($inBody, '01K5Z9P2QH7N3X4C8V6B0M1T9A'), $text], []],
        'the site\'s default social image' => ['Harbour news', [], [], [$text], [SocialCards::DEFAULT_IMAGE => $social->id]],
    };

    // An empty set is no override at all: the settings row holds an object, never a list.
    if ($settings !== []) {
        cmSettings($settings, SearchAndSocial::KEY);
    }
    $item = headPost($this->editor, $title, $fields, $terms, $nodes);
    $page = ($this->head)('/'.addrCurrent('content', $item->id).'/');
    $preview = ($this->preview)($item);
    $image = match ($imageFrom) {
        'social', 'default' => $social->id,
        'featured' => $featured->id,
        'body' => $inBody->id,
        default => null,
    };

    expect($preview['title'])->toBe($page['title'])
        ->and($preview['description'] ?? '')->toBe($page['description'])
        ->and($preview['social']['title'])->toBe($page['socialTitle'])
        ->and($preview['social']['description'] ?? '')->toBe($page['socialDescription'])
        ->and($preview['social']['imageId'])->toBe($image)
        ->and($preview['social']['imageFrom'])->toBe($imageFrom === 'none' ? null : $imageFrom)
        ->and($image === null ? $page['image'] : str_contains($page['image'], $image))->toBe($image === null ? '' : true);
})->with([
    'its SEO title, word for word' => ['its SEO title, word for word', 'none'],
    'the template, its primary category for {term}' => ['the template, its primary category for {term}', 'none'],
    'the template, an empty {term} and its separator dropped' => ['the template, an empty {term} and its separator dropped', 'none'],
    'a template naming {site} inside the item\'s title' => ['a template naming {site} inside the item\'s title', 'none'],
    'the template\'s type, author, date and year' => ['the template\'s type, author, date and year', 'none'],
    'its meta description' => ['its meta description', 'none'],
    'its excerpt, with no meta description' => ['its excerpt, with no meta description', 'none'],
    'its social title and description' => ['its social title and description', 'none'],
    'its social image over its featured image' => ['its social image over its featured image', 'social'],
    'its featured image, with no social image' => ['its featured image, with no social image', 'featured'],
    'the first image in its body' => ['the first image in its body', 'body'],
    'the site\'s default social image' => ['the site\'s default social image', 'default'],
]);

it('makes the home page\'s and the posts page\'s results as the heads of their addresses do (I-903)', function (string $which): void {
    $home = addrPublished($this->editor, 'Welcome', 'page');
    $posts = addrPublished($this->editor, 'News', 'page');
    addrChange(User::factory()->create()->assignRole('administrator'), ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $home->id, 'postsPageId' => $posts->id]);
    $item = $which === 'the home page' ? $home : $posts;

    $page = ($this->head)($which === 'the home page' ? '/' : '/'.addrCurrent('content', $posts->id).'/');
    $preview = ($this->preview)($item->refresh());

    expect($preview['title'])->toBe($page['title'])
        ->and($preview['description'] ?? '')->toBe($page['description'])
        ->and($preview['social']['title'])->toBe($page['socialTitle']);
})->with(['the home page', 'the posts page']);

it('says whether search engines may list the item exactly when its robots meta does (I-291, I-853)', function (string $case, string $listing): void {
    match ($case) {
        'the whole site discouraged' => cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY),
        'its type hidden' => cmSettings(['hide_type_post' => true], SearchAndSocial::KEY),
        default => null,
    };
    $item = headPost($this->editor, 'Harbour news', $case === 'hidden on its own' ? [BuiltInFields::NOINDEX => true] : []);
    $session = EditorSession::for($item->id, (string) $this->editor->id);

    $preview = guardNode('search-preview.mjs', ['listing' => ['fields' => (object) $session->fields, 'seo' => app(EditorSearchAndSocial::class)->for($session)]]);

    expect($preview['listing'])->toBe($listing)
        ->and(str_contains(($this->head)('/harbour-news/')['robots'], 'noindex'))->toBe($listing !== 'listed');
})->with([
    'listed' => ['listed', 'listed'],
    'hidden on its own' => ['hidden on its own', 'hidden'],
    'its type hidden' => ['its type hidden', 'type-hidden'],
    'the whole site discouraged' => ['the whole site discouraged', 'discouraged'],
]);

it('lets the browser send only an outside address the server takes, and refuses the rest in the server\'s words (I-292)', function (string $address, ?string $words): void {
    $browser = guardNode('search-preview.mjs', ['address' => $address, 'siteHost' => 'localhost'])['problem'];
    $server = Validator::make(['canonical' => ['address' => $address]], ['canonical' => [new CanonicalChoice]]);

    expect($browser)->toBe($words)
        ->and($server->fails())->toBe($words !== null);
})->with([
    'a whole address on another site' => ['https://example.org/original-story', null],
    'with a port, a query and brackets' => ['https://example.org:8443/a_(b)?c=1&d=2#e', null],
    'an empty box' => ['', 'Enter the whole address, starting https://.'],
    'no scheme' => ['example.org/original', 'Enter the whole address, starting https://.'],
    'another scheme' => ['javascript:alert(1)', 'Enter the whole address, starting https://.'],
    'a space inside' => ['https://example.org/a story', 'Enter the whole address, starting https://.'],
    'a host that isn\'t ASCII' => ['https://exämple.org/original', 'Enter the whole address, starting https://.'],
    'this site\'s own host' => ['http://localhost/ferry-fares/', 'That address is on this site. Choose its item instead, so the link survives a move.'],
]);
