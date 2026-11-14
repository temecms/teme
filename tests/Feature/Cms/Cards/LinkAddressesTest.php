<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\References;
use App\Domain\Feeds\FeedBody;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('keeps a link it can make sense of, and removes only the link from one it cannot', function (string $href, ?string $kept): void {
    $clean = DocumentNormaliser::normalise(docWith(guardLink($href, 'the words')));
    $text = $clean['content'][0]['content'][0];

    expect($text['text'])->toBe('the words')
        ->and($text['marks'][0]['attrs']['href'] ?? null)->toBe($kept);

    docValidate($clean);
})->with([
    'a web address' => ['https://example.org/tickets?day=1', 'https://example.org/tickets?day=1'],
    'an email address' => ['mailto:box@example.org', 'mailto:box@example.org'],
    'a phone number' => ['tel:+441234567890', 'tel:+441234567890'],
    'an address with no scheme' => ['//example.org/map', 'https://example.org/map'],
    'a place on the page' => ['#getting-there', '#getting-there'],
    'a place from a Google Docs contents list' => ['#heading=h.3znysh7', '#heading=h.3znysh7'],
    'a place with a space and an accent' => ['#getting there café', '#getting%20there%20caf%C3%A9'],
    'spaces around an address' => ['  https://example.org/  ', 'https://example.org/'],
    'an internal link in capitals' => ['teme:content/01K5Z9P2QH7N3X4C8V6B0M1T2R', 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r'],
    'an internal link to a place, in capitals' => ['teme:content/01K5Z9P2QH7N3X4C8V6B0M1T2R#Getting There', 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#Getting%20There'],
    'an internal link to a place with no name' => ['teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#', 'teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r'],
    'a path on this site no article has' => ['/no-such-page/', '/no-such-page/'],
    'the home page' => ['/', '/'],
    'a path with a space, an accent, a query and a place' => ['/café menu/?q=a b#top', '/caf%C3%A9%20menu/?q=a%20b#top'],
    'a place with no name' => ['#', null],
    'an internal link that names no article' => ['teme:content/../x', null],
    'javascript' => ['javascript:alert(1)', null],
    'data' => ['data:text/html,<b>hi</b>', null],
    'vbscript' => ['vbscript:msgbox(1)', null],
    'a file' => ['file:///C:/notes.txt', null],
    'ftp' => ['ftp://example.org/files', null],
    'a scheme nobody allows' => ['sms:+441234567890', null],
    'a path relative to another page' => ['page.html', null],
    'an address with three slashes' => ['https:///harbour-festival/', null],
    'a path a browser reads as another site' => ['/\\evil.example/', null],
    'three slashes, after a space' => [' ///evil.example/', null],
]);

it('turns a link to an article\'s path into a link to the article, current address or old, keeping its place (I-1670)', function (): void {
    $story = addrPublished($this->editor, 'Harbour festival');
    addrRename($story, 'harbour-festival-2026', $this->editor);
    $links = [];

    $clean = DocumentNormaliser::normalise(docWith(
        guardLink('/harbour-festival-2026/', 'now'),
        guardLink('/harbour-festival/?utm_source=mail#tickets', 'before, to a place'),
        guardLink('/Harbour-Festival-2026', 'in capitals, without the slash'),
    ), $links);

    expect(array_map(fn (array $block): string => $block['content'][0]['marks'][0]['attrs']['href'], $clean['content']))
        ->toBe(["teme:content/{$story->id}", "teme:content/{$story->id}#tickets", "teme:content/{$story->id}"])
        ->and($links)->toBe([
            ['from' => '/harbour-festival-2026/', 'to' => "teme:content/{$story->id}"],
            ['from' => '/harbour-festival/?utm_source=mail#tickets', 'to' => "teme:content/{$story->id}#tickets"],
            ['from' => '/Harbour-Festival-2026', 'to' => "teme:content/{$story->id}"],
        ]);
});

it('stores a link to a category’s listing by the category’s ID, keeping its place (I-1702)', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    $path = addrCurrent('term', $news->id);
    $links = [];

    expect($path)->not->toBeNull()
        ->and(DocumentNormaliser::normalise(docWith(guardLink("/{$path}/#latest", 'the news')), $links)['content'][0]['content'][0]['marks'][0]['attrs']['href'])
        ->toBe("teme:term/{$news->id}#latest")
        ->and($links)->toBe([['from' => "/{$path}/#latest", 'to' => "teme:term/{$news->id}#latest"]]);
});

it('reports only the addresses it changed', function (): void {
    $links = [];

    DocumentNormaliser::normalise(docWith(
        guardLink('https://example.org/', 'kept'),
        guardLink('#getting-there', 'kept too'),
        guardLink('/no-such-page/', 'a path no article has, kept'),
        guardLink('//example.org/map', 'given a scheme'),
        guardLink('ftp://example.org/files', 'removed'),
    ), $links);

    expect($links)->toBe([
        ['from' => '//example.org/map', 'to' => 'https://example.org/map'],
        ['from' => 'ftp://example.org/files', 'to' => null],
    ]);
});

it('looks each path up once per document', function (): void {
    addrPublished($this->editor, 'Harbour festival');
    $lookups = 0;

    DB::listen(function (QueryExecuted $query) use (&$lookups): void {
        if (str_contains($query->sql, '"paths"')) {
            $lookups++;
        }
    });

    DocumentNormaliser::normalise(docWith(
        guardLink('/harbour-festival/', 'one'),
        guardLink('/harbour-festival/', 'two'),
        guardLink('/no-such-page/', 'three'),
        guardLink('/no-such-page/', 'four'),
    ));

    expect($lookups)->toBe(2);
});

it('stores pasted links to this site\'s paths through the Operation, by ID where an article has the path', function (): void {
    $story = addrPublished($this->editor, 'Harbour festival');
    $content = lifecycleDraft($this->editor, 'Weekend guide');

    $stored = docSave($content, $this->editor, docWith(
        guardLink('/harbour-festival/#tickets', 'the festival\'s tickets'),
        guardLink('#getting-there', 'getting there'),
        guardLink('/feed/', 'the feed'),
    ))->workingRevision->document;

    expect($stored['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe("teme:content/{$story->id}#tickets")
        ->and($stored['content'][1]['content'][0]['marks'][0]['attrs']['href'])->toBe('#getting-there')
        ->and($stored['content'][2]['content'][0]['marks'][0]['attrs']['href'])->toBe('/feed/');
});

it('accepts a place, a site path and a place in an article, and refuses what a browser reads as another site', function (string $href, bool $accepted): void {
    $check = fn () => docValidate(docWith(guardLink($href, 'words')));

    $accepted ? expect($check)->not->toThrow(Throwable::class) : expect($check)->toThrow(ValidationException::class);
})->with([
    'a place on the page' => ['#getting-there', true],
    'a path on this site' => ['/category/news/?page=2#latest', true],
    'the home page' => ['/', true],
    'a place in an article' => ['teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#tickets', true],
    'two slashes' => ['//evil.example/', false],
    'a backslash' => ['/\\evil.example/', false],
    'an unencoded space in a path' => ['/café menu/', false],
    'an empty place after an article' => ['teme:content/01k5z9p2qh7n3x4c8v6b0m1t2r#', false],
]);

it('draws a link to a place in an article at the article\'s current address, followed by the place (I-1670)', function (): void {
    $story = addrPublished($this->editor, 'Harbour festival');
    $url = Addresses::relativeUrl($story);

    expect($url)->not->toBeNull()
        ->and(app(DocumentRenderer::class)->html(docWith(guardLink("teme:content/{$story->id}#tickets", 'tickets'))))
        ->toBe('<p><a href="'.$url.'#tickets">tickets</a></p>')
        ->and(References::contentIds(docWith(guardLink("teme:content/{$story->id}#tickets", 'tickets'))))
        ->toBe([$story->id]);
});

it('draws a path on this site as it is on the site\'s pages, and absolute in a feed (I-1669)', function (): void {
    $document = docWith(guardLink('/category/news/', 'the news'));

    expect(app(DocumentRenderer::class)->html($document))->toBe('<p><a href="/category/news/">the news</a></p>')
        ->and(app(FeedBody::class)->html($document))->toContain('<a href="'.rtrim(url('/'), '/').'/category/news/">the news</a>');
});

it('stores a link to this site\'s own full address by ID, or as its path, never with the site\'s host (rule 9)', function (): void {
    // The rule reads config('app.url') on each call, so this holds whatever the test environment says.
    config(['app.url' => 'https://harbour.example']);
    $story = addrPublished($this->editor, 'Harbour festival');
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    $listing = addrCurrent('term', $news->id);
    $links = [];

    $clean = DocumentNormaliser::normalise(docWith(
        guardLink('https://harbour.example/harbour-festival/', 'https'),
        guardLink('http://harbour.example/harbour-festival', 'http, without the slash'),
        guardLink('https://Harbour.Example/harbour-festival/?utm_source=mail#tickets', 'capitals, a query and a place'),
        guardLink('//harbour.example/harbour-festival/', 'no scheme'),
        guardLink('https://www.harbour.example/harbour-festival/', 'www'),
        guardLink('https://harbour.example:443/harbour-festival/', 'the https port written out'),
        guardLink("https://harbour.example/{$listing}/", 'a listing'),
        guardLink('https://harbour.example/no-such-page/', 'nothing there'),
        guardLink('https://harbour.example', 'the home page'),
        guardLink('https://elsewhere.example/harbour-festival/', 'another site'),
        guardLink('https://harbour.example:8443/harbour-festival/', 'another port'),
        guardLink('https://ada@harbour.example/harbour-festival/', 'a user name'),
    ), $links);

    $article = "teme:content/{$story->id}";

    expect(array_map(fn (array $block): ?string => $block['content'][0]['marks'][0]['attrs']['href'] ?? null, $clean['content']))
        ->toBe([
            $article, $article, "{$article}#tickets", $article, $article, $article,
            "teme:term/{$news->id}", '/no-such-page/', '/',
            'https://elsewhere.example/harbour-festival/',
            'https://harbour.example:8443/harbour-festival/',
            'https://ada@harbour.example/harbour-festival/',
        ])
        ->and($links)->toBe([
            ['from' => 'https://harbour.example/harbour-festival/', 'to' => $article],
            ['from' => 'http://harbour.example/harbour-festival', 'to' => $article],
            ['from' => 'https://Harbour.Example/harbour-festival/?utm_source=mail#tickets', 'to' => "{$article}#tickets"],
            ['from' => '//harbour.example/harbour-festival/', 'to' => $article],
            ['from' => 'https://www.harbour.example/harbour-festival/', 'to' => $article],
            ['from' => 'https://harbour.example:443/harbour-festival/', 'to' => $article],
            ['from' => "https://harbour.example/{$listing}/", 'to' => "teme:term/{$news->id}"],
            ['from' => 'https://harbour.example/no-such-page/', 'to' => '/no-such-page/'],
            ['from' => 'https://harbour.example', 'to' => '/'],
        ]);

    docValidate($clean);
});

it('stores a pasted link to this site\'s full address as a link to the article, through the Operation', function (): void {
    config(['app.url' => 'https://harbour.example']);
    $story = addrPublished($this->editor, 'Harbour festival');
    $content = lifecycleDraft($this->editor, 'Weekend guide');

    $stored = docSave($content, $this->editor, docWith(guardLink('https://harbour.example/harbour-festival/#tickets', 'the festival\'s tickets')))->workingRevision->document;

    expect($stored['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe("teme:content/{$story->id}#tickets");
});
