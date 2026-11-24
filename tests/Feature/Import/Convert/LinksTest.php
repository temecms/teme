<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\Conversion;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\KnownTargets;
use App\Domain\Import\Convert\SourceTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

/*
 * Links to the old site by ID (I-1702): what the import made of the page,
 * the term, the person or the file, or the path for the second pass; never
 * the old site's own address.
 */

beforeEach(function (): void {
    $this->article = '01k5z9p2qh7n3x4c8v6b0m1a01';
    $this->about = '01k5z9p2qh7n3x4c8v6b0m1a02';
    $this->boats = '01k5z9p2qh7n3x4c8v6b0m1t01';
    $this->sailing = '01k5z9p2qh7n3x4c8v6b0m1t02';
    $this->timetable = '01K5Z9P2QH7N3X4C8V6B0M1F01';
    $this->harbour = '01K5Z9P2QH7N3X4C8V6B0M1F02';
    $this->targets = new KnownTargets(
        pages: [
            '/ferries' => ['kind' => 'content', 'id' => $this->article],
            '/category/boats' => ['kind' => 'term', 'id' => $this->boats],
            '/author/ada' => ['kind' => 'user', 'id' => '42'],
            '/ferries/timetable' => ['kind' => 'media', 'id' => $this->timetable],
        ],
        posts: [12 => $this->article, 7 => $this->about],
        terms: ['category:3' => $this->boats, 'post_tag:9' => $this->sailing],
        termSlugs: ['category:boats' => $this->boats, 'post_tag:sailing' => $this->sailing],
        users: [42 => '42'],
        userSlugs: ['ada' => '42'],
    );
    // Each address as a link in a paragraph of its own, in a classic chunk: what each link keeps (null when only its words stay), the paths for the second pass, and what was said.
    $this->links = function (array $hrefs, array $attachments = [], array $files = []): array {
        $html = implode('', array_map(fn (string $href): string => '<p><a href="'.e($href).'">words</a></p>', $hrefs));
        $conversion = WordPressContent::convert([WordPressContent::classic($html)], $this->targets, attachments: $attachments, files: $files);
        wpAccepted($conversion->document);

        return [
            array_map(fn (array $paragraph): ?string => $paragraph['content'][0]['marks'][0]['attrs']['href'] ?? null, $conversion->document['content']),
            $conversion->pendingLinks,
            WordPressContent::said($conversion),
        ];
    };
});

it('stores a link to the old site’s article, category, author or attachment page by ID, its place kept and its query dropped', function (): void {
    [$kept, $pending, $said] = ($this->links)([
        'https://old.example/ferries/?utm_source=news#times',
        'http://www.old.example/category/boats/',
        '/author/ada/',
        'https://old.example/ferries/timetable/',
        'https://OLD.example/Ferries',
    ]);

    expect($kept)->toBe([
        DocumentSchema::linkTo('content', $this->article, '#times'),
        DocumentSchema::linkTo('term', $this->boats),
        DocumentSchema::linkTo('user', '42'),
        DocumentSchema::linkTo('media', $this->timetable),
        DocumentSchema::linkTo('content', $this->article),
    ])->and($pending)->toBe([])
        ->and($said)->toBe([]);
});

it('reads WordPress’s plain addresses by their query', function (): void {
    [$kept, $pending] = ($this->links)([
        '/?p=12',
        'https://old.example/index.php?page_id=7',
        '/?attachment_id=30',
        '/?p=30',
        '/?cat=3',
        '/?tag_id=9',
        '/?category_name=harbour/boats',
        '/?tag=sailing',
        '/?author=42',
        '/?author_name=ada#posts',
    ], attachments: [30 => $this->harbour]);

    expect($kept)->toBe([
        DocumentSchema::linkTo('content', $this->article),
        DocumentSchema::linkTo('content', $this->about),
        DocumentSchema::linkTo('media', $this->harbour),
        DocumentSchema::linkTo('media', $this->harbour),
        DocumentSchema::linkTo('term', $this->boats),
        DocumentSchema::linkTo('term', $this->sailing),
        DocumentSchema::linkTo('term', $this->boats),
        DocumentSchema::linkTo('term', $this->sailing),
        DocumentSchema::linkTo('user', '42'),
        DocumentSchema::linkTo('user', '42', '#posts'),
    ])->and($pending)->toBe([]);
});

it('keeps an address the import has not reached yet as its path, for the second pass, never the front page', function (): void {
    $this->targets = new KnownTargets(pages: ['/' => ['kind' => 'content', 'id' => $this->about]]);

    [$kept, $pending, $said] = ($this->links)([
        'https://www.old.example/later-story/?ref=home#end',
        '/?p=900',
        '/?s=boats',
        'https://old.example/café/',
        '/later-story/?ref=home#end',
        'https://old.example/',
    ]);

    expect($kept)->toBe([
        '/later-story/?ref=home#end',
        '/?p=900',
        '/?s=boats',
        '/caf%C3%A9/',
        '/later-story/?ref=home#end',
        DocumentSchema::linkTo('content', $this->about),
    ])->and($pending)->toBe(['/later-story/?ref=home#end', '/?p=900', '/?s=boats', '/caf%C3%A9/'])
        ->and($said)->toBe([]);
});

it('keeps a later page of a list as its path, and not for the second pass', function (): void {
    [$kept, $pending] = ($this->links)(['https://old.example/category/boats/page/2/', '/?cat=3&paged=2']);

    expect($kept)->toBe(['/category/boats/page/2/', '/?cat=3&paged=2'])
        ->and($pending)->toBe([]);
});

it('stores a link to a file in any size by the file’s ID, and keeps only the words of a link to a file Teme lacks', function (): void {
    [$kept, $pending, $said] = ($this->links)([
        'https://old.example/wp-content/uploads/2024/05/Timetable.pdf?v=2#page=3',
        '//old.example/wp-content/uploads/2024/05/harbour-1024x683.jpg',
        '/wp-content/uploads/2023/01/Gone.pdf',
    ], files: ['2024/05/timetable.pdf' => $this->timetable, '2024/05/harbour.jpg' => $this->harbour]);

    expect($kept)->toBe([
        DocumentSchema::linkTo('media', $this->timetable, '#page=3'),
        DocumentSchema::linkTo('media', $this->harbour),
        null,
    ])->and($pending)->toBe([])
        ->and($said)->toBe([
            ['file.missing', 'Kept the words of 1 link to a file that isn’t in Teme’s library, without the link: Gone.pdf.', ['count' => 1, 'files' => ['Gone.pdf']]],
        ]);
});

it('keeps other sites’ addresses, mail and phone links and places on the page as they are, and removes what has nowhere to go', function (): void {
    [$kept, $pending, $said] = ($this->links)(['https://other.example/x?y=1', 'mailto:ada@example.com', 'tel:+441234', '#top', 'javascript:alert(1)']);

    expect($kept)->toBe(['https://other.example/x?y=1', 'mailto:ada@example.com', 'tel:+441234', '#top', null])
        ->and($pending)->toBe([])
        ->and($said)->toBe([['link.removed', 'Kept the words of 1 link whose address Teme can’t keep, without the link.', ['count' => 1]]]);
});

it('says an image Teme lacks inside a link to its own file once', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic('<p>See <a href="https://old.example/wp-content/uploads/2024/05/gone.jpg"><img src="https://old.example/wp-content/uploads/2024/05/gone-300x200.jpg" alt="Gone"></a> here.</p>')]);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::p('See here.')]))
        ->and(array_column(WordPressContent::said($conversion), 0))->toBe(['image.missing']);
});

it('writes each ID in its table’s case, whatever the caller gives', function (): void {
    $this->targets = new KnownTargets(posts: [12 => strtoupper($this->article)]);

    [$kept] = ($this->links)(['/?p=12', '/?attachment_id=30'], attachments: [30 => strtolower($this->harbour)]);

    expect($kept)->toBe([DocumentSchema::linkTo('content', $this->article), DocumentSchema::linkTo('media', $this->harbour)]);
});

it('turns a button to an article on the old site into a button to the article, and any other button to the old site into a linked paragraph', function (): void {
    $button = fn (string $href, string $label): array => wpBlock('core/button', "<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"{$href}\">{$label}</a></div>");

    $conversion = WordPressContent::convert([wpBlock('core/buttons', '<div class="wp-block-buttons">%s</div>', [], [
        $button('https://old.example/ferries/', 'Timetables'),
        $button('/category/boats/', 'All boats'),
        $button('/later-story/', 'Read on'),
        $button('https://shop.example/buy', 'Buy'),
    ])], $this->targets);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        ['type' => 'button', 'attrs' => ['label' => 'Timetables', 'contentId' => $this->article, 'url' => null, 'style' => 'primary']],
        WordPressContent::linked('All boats', DocumentSchema::linkTo('term', $this->boats)),
        WordPressContent::linked('Read on', '/later-story/'),
        ['type' => 'button', 'attrs' => ['label' => 'Buy', 'contentId' => null, 'url' => 'https://shop.example/buy', 'style' => 'primary']],
    ])->and($conversion->pendingLinks)->toBe(['/later-story/']);
});

it('links an image from another site that the choices step did not bring in, its alt text as the words, and shows one it did', function (): void {
    $this->targets = new KnownTargets(outside: ['https://cdn.partner.example/dog.jpg' => $this->harbour]);
    $image = fn (string $src, string $alt): array => wpBlock('core/image', "<figure class=\"wp-block-image\"><img src=\"{$src}\" alt=\"{$alt}\"/></figure>");

    $conversion = WordPressContent::convert([
        $image('https://photos.friend.example/cat.jpg', 'A cat asleep'),
        $image('https://photos.friend.example/fox.jpg', ''),
        $image('https://cdn.partner.example/dog.jpg', 'A dog'),
    ], $this->targets);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::linked('A cat asleep', 'https://photos.friend.example/cat.jpg'),
        WordPressContent::linked('fox.jpg', 'https://photos.friend.example/fox.jpg'),
        WordPressContent::image($this->harbour, 'A dog'),
    ])->and(WordPressContent::said($conversion))->toBe([
        ['image.outside', 'Linked to 2 images on other sites instead of showing them: photos.friend.example.', ['count' => 2, 'hosts' => ['photos.friend.example']]],
    ]);
});

it('shows an own image the outside step brought, and still leaves one out when it was not brought', function (): void {
    $src = 'https://old.example/wp-content/uploads/2024/05/Teeth-Whitening.jpg';
    $image = fn (?SourceTargets $targets = null): Conversion => WordPressContent::convert([
        WordPressContent::classic('<p>Before.</p>'),
        wpBlock('core/image', "<figure class=\"wp-block-image\"><img src=\"{$src}\" alt=\"Teeth\" class=\"wp-image-99\"/></figure>", ['id' => 99]),
    ], $targets);

    $brought = $image(new KnownTargets(outside: [$src => $this->harbour]));
    wpAccepted($brought->document);
    $missing = $image();
    wpAccepted($missing->document);

    expect(wpWithoutIds($brought->document)['content'])->toBe([
        WordPressContent::p('Before.'),
        WordPressContent::image($this->harbour, 'Teeth'),
    ])->and(WordPressContent::said($brought))->toBe([])
        ->and(wpWithoutIds($missing->document)['content'])->toBe([WordPressContent::p('Before.')])
        ->and(WordPressContent::said($missing))->toBe([
            ['image.missing', 'Left out 1 image that isn’t in Teme’s library: Teeth-Whitening.jpg.', ['count' => 1, 'files' => ['Teeth-Whitening.jpg']]],
        ]);
});

it('keeps the link to an image from another site after the gallery it was in', function (): void {
    $media = [1 => '01K5Z9P2QH7N3X4C8V6B0M1M01', 2 => '01K5Z9P2QH7N3X4C8V6B0M1M02'];
    $own = fn (int $id): array => wpBlock('core/image', "<figure class=\"wp-block-image\"><img src=\"https://old.example/wp-content/uploads/i{$id}.jpg\" class=\"wp-image-{$id}\"/></figure>", ['id' => $id]);

    $conversion = WordPressContent::convert([wpBlock('core/gallery', '<figure class="wp-block-gallery has-nested-images">%s</figure>', [], [
        $own(1),
        wpBlock('core/image', '<figure class="wp-block-image"><img src="https://photos.friend.example/cat.jpg" alt="A cat"/></figure>'),
        $own(2),
    ])], attachments: $media);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        ['type' => 'gallery', 'attrs' => ['mediaIds' => [$media[1], $media[2]], 'caption' => null, 'layout' => 'grid']],
        WordPressContent::linked('A cat', 'https://photos.friend.example/cat.jpg'),
    ])->and(array_column(WordPressContent::said($conversion), 0))->toBe(['image.outside']);
});

it('reads the old site’s own pages on any scheme, with or without www, and nothing else', function (string $href, ?array $parts): void {
    expect((new ConversionSite('https://www.old.example'))->pageOf($href))->toBe($parts);
})->with([
    'its home' => ['https://old.example', ['path' => '/', 'query' => '', 'place' => '']],
    'http and www, a query and a place' => ['http://www.old.example/ferries/?a=1#times', ['path' => '/ferries/', 'query' => 'a=1', 'place' => '#times']],
    'a path' => ['/category/boats/', ['path' => '/category/boats/', 'query' => '', 'place' => '']],
    'another site' => ['https://other.example/ferries/', null],
    'a user name' => ['https://ada@old.example/', null],
    'another site without a scheme' => ['//other.example/x', null],
    'a place on the page' => ['#top', null],
]);

it('makes one key for a page however its path is written', function (): void {
    expect(ConversionSite::pageKey('/Caf%C3%A9/'))->toBe('/café')
        ->and(ConversionSite::pageKey('/café'))->toBe('/café')
        ->and(ConversionSite::pageKey('/'))->toBe('/')
        ->and(ConversionSite::pageKey(''))->toBe('/');
});
