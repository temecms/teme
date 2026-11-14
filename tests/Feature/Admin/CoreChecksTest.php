<?php

declare(strict_types=1);

beforeEach(function (): void {
    // What the checks read when every one passes; a test overrides what it is about.
    $this->passing = [
        'document' => ['hasTitle' => true, 'missingAlt' => 0, 'hasExcerpt' => true, 'hasFeaturedImage' => true, 'badLinks' => []],
        'imageCards' => 1,
        'featuredMissingAlt' => false,
        'hasExcerptField' => true,
        'hasFeaturedImageField' => true,
        'description' => ['text' => 'What the new fares mean.', 'from' => 'meta'],
        'titleWidth' => 300,
        'internalLinks' => 2,
        'listing' => 'listed',
        'published' => false,
        'publishing' => false,
        'noun' => 'post',
        'plural' => 'posts',
        'withheld' => null,
    ];

    // The list for these inputs, keyed by check, each its state and its words.
    $this->checks = function (array $changes = []): array {
        $list = guardNode('core-checks.mjs', ['checks' => array_replace_recursive($this->passing, $changes)]);

        return array_column(array_map(fn (array $check): array => [...$check, 'says' => "{$check['label']}: {$check['message']}"], $list), null, 'id');
    };
});

it('lists core\'s checks once, in one order, the search ones marked, each core\'s own (I-880, I-200)', function (): void {
    $checks = ($this->checks)();

    expect(array_keys($checks))->toBe(['title', 'description', 'title-width', 'excerpt', 'featured', 'alt', 'internal-links', 'links', 'listing'])
        ->and(array_keys(array_filter($checks, fn (array $check): bool => $check['search'])))->toBe(['description', 'title-width', 'alt', 'internal-links', 'listing'])
        ->and(array_unique(array_column($checks, 'owner')))->toBe(['core'])
        ->and(array_unique(array_column($checks, 'state')))->toBe(['ok']);
});

it('leaves out the excerpt and featured image checks for a type that has neither', function (): void {
    $checks = ($this->checks)(['hasExcerptField' => false, 'hasFeaturedImageField' => false]);

    expect($checks)->not->toHaveKey('excerpt')->not->toHaveKey('featured');
});

it('says where the description comes from, or that there is none (I-290)', function (array $description, bool $hasExcerptField, string $state, string $says): void {
    $check = ($this->checks)(['description' => $description, 'hasExcerptField' => $hasExcerptField])['description'];

    expect([$check['state'], $check['says']])->toBe([$state, $says]);
})->with([
    'its own' => [['text' => 'x', 'from' => 'meta'], true, 'ok', 'Description: From the meta description.'],
    'the excerpt' => [['text' => 'x', 'from' => 'excerpt'], true, 'ok', 'Description: From the excerpt.'],
    'the tagline' => [['text' => 'x', 'from' => 'tagline'], true, 'ok', 'Description: From the site’s tagline.'],
    'none' => [['text' => null, 'from' => null], true, 'warn', 'Description: None, and no excerpt. Search engines will choose text from the page.'],
    'none, for a type with no excerpt' => [['text' => null, 'from' => null], false, 'warn', 'Description: None. Search engines will choose text from the page.'],
]);

it('warns when the title is wider than results show, in about the pixels (I-882)', function (float $width, string $state, string $says): void {
    $check = ($this->checks)(['titleWidth' => $width])['title-width'];

    expect([$check['state'], $check['says']])->toBe([$state, $says]);
})->with([
    'just fits' => [580, 'ok', 'Title in results: It fits.'],
    'too wide' => [612.4, 'warn', 'Title in results: Too long: about 612 pixels, where results show about 580.'],
]);

it('counts the images with no alt text, the featured one named (I-286)', function (int $body, bool $featured, int $cards, string $state, string $says): void {
    $check = ($this->checks)(['document' => ['missingAlt' => $body], 'featuredMissingAlt' => $featured, 'imageCards' => $cards])['alt'];

    expect([$check['state'], $check['says']])->toBe([$state, $says]);
})->with([
    'every one has it' => [0, false, 2, 'ok', 'Alt text: Every image has alt text.'],
    'no image cards, a featured image with its alt' => [0, false, 0, 'ok', 'Alt text: Every image has alt text.'],
    'one in the body' => [1, false, 2, 'warn', 'Alt text: 1 image in the body has no alt text.'],
    'three in the body' => [3, false, 3, 'warn', 'Alt text: 3 images in the body have no alt text.'],
    'the featured one' => [0, true, 0, 'warn', 'Alt text: The featured image has no alt text.'],
    'the featured one and one in the body' => [1, true, 1, 'warn', 'Alt text: The featured image and 1 image in the body have no alt text.'],
]);

it('says "No images." only when there is neither an image card nor a featured image', function (): void {
    $none = ($this->checks)(['imageCards' => 0, 'document' => ['hasFeaturedImage' => false]])['alt'];
    $featuredOnly = ($this->checks)(['imageCards' => 0])['alt'];

    expect($none['says'])->toBe('Alt text: No images.')
        ->and($featuredOnly['says'])->toBe('Alt text: Every image has alt text.');
});

it('counts the links to other items on this site (I-286)', function (int $links, string $state, string $says): void {
    $check = ($this->checks)(['internalLinks' => $links])['internal-links'];

    expect([$check['state'], $check['says']])->toBe([$state, $says]);
})->with([
    'none' => [0, 'warn', 'Internal links: None to other items on this site.'],
    'one' => [1, 'ok', 'Internal links: 1 link to other items on this site.'],
    'three' => [3, 'ok', 'Internal links: 3 links to other items on this site.'],
]);

it('says whether search engines may list it, in words for the screen and for the confirmation (I-291, I-853, I-880)', function (string $listing, bool $published, bool $publishing, string $state, string $says): void {
    $check = ($this->checks)(['listing' => $listing, 'published' => $published, 'publishing' => $publishing])['listing'];

    expect([$check['state'], $check['says']])->toBe([$state, $says]);
})->with([
    'listed' => ['listed', false, false, 'ok', 'Search engines: They may list it.'],
    'the whole site discouraged' => ['discouraged', true, false, 'warn', 'Search engines: Search engines are asked to stay away from the whole site.'],
    'its type hidden' => ['type-hidden', true, false, 'warn', 'Search engines: Search and social hides posts from search engines.'],
    'hidden, a draft' => ['hidden', false, false, 'warn', 'Search engines: Hidden from search engines when it’s published.'],
    'hidden, published' => ['hidden', true, false, 'warn', 'Search engines: Hidden from search engines, though it’s published.'],
    'hidden, in the confirmation' => ['hidden', false, true, 'warn', 'Search engines: It will be published hidden from search engines.'],
]);

it('keeps the rail\'s own checks as facts: the title, the excerpt, the featured image and links readers can\'t reach (I-427)', function (): void {
    $bad = [['id' => '01k5z9p2qh7n3x4c8v6b0m1t2c', 'title' => 'Lantern parade', 'state' => 'trashed']];
    $checks = ($this->checks)(['document' => ['hasTitle' => false, 'hasExcerpt' => false, 'hasFeaturedImage' => false, 'badLinks' => $bad]]);

    expect(array_map(fn (string $id): string => $checks[$id]['says'], ['title', 'excerpt', 'featured', 'links']))->toBe([
        'Title: No title yet.',
        'Excerpt: No excerpt yet.',
        'Featured image: None. Social cards use the first image in the body, or the site’s default.',
        'Links: Some lead to an item readers can’t reach:',
    ])
        ->and($checks['links']['badLinks'])->toBe($bad)
        ->and(($this->checks)()['links']['says'])->toBe('Links: None lead to a deleted or unpublished item.');
});

it('counts a document\'s image cards, galleries included, and its distinct links to other items, never to itself', function (): void {
    $own = '01k5z9p2qh7n3x4c8v6b0m1t2a';
    $other = '01k5z9p2qh7n3x4c8v6b0m1t2b';
    $document = docWith(
        ['type' => 'image', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3A', 'mediaId' => '01K5Z9P2QH7N3X4C8V6B0M1T4A']],
        ['type' => 'gallery', 'attrs' => ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3B', 'mediaIds' => ['01K5Z9P2QH7N3X4C8V6B0M1T4B']]],
        schedLinkTo($other, 'the market'),
        schedLinkTo($other, 'the market again'),
        schedLinkTo($own, 'this very post'),
    );

    expect(guardNode('core-checks.mjs', ['document' => $document, 'ownId' => $own]))->toBe(['images' => 2, 'links' => 1]);
});

it('says the search checks in one line: every warning by name in the list’s order, then how many of the rest pass (I-3124, I-880)', function (array $changes, string $says): void {
    $line = guardNode('core-checks.mjs', ['line' => array_replace_recursive($this->passing, $changes)]);
    $words = trim(implode(' ', [
        ...array_map(fn (array $warning): string => "{$warning['label']}. {$warning['message']}", $line['warnings']),
        $line['summary'],
    ]));

    expect($words)->toBe($says);
})->with([
    'all pass' => [[], 'All 5 search checks pass.'],
    'all pass, structured data checked' => [['withheld' => []], 'All 6 search checks pass.'],
    'one warning' => [['titleWidth' => 640], 'Title in results. Too long: about 640 pixels, where results show about 580. The other 4 search checks pass.'],
    'two warnings' => [['titleWidth' => 640, 'listing' => 'hidden'], 'Title in results. Too long: about 640 pixels, where results show about 580. Search engines. Hidden from search engines when it’s published. The other 3 search checks pass.'],
    'one passes' => [
        ['description' => ['text' => null, 'from' => null], 'titleWidth' => 640, 'internalLinks' => 0, 'listing' => 'hidden'],
        'Description. None, and no excerpt. Search engines will choose text from the page. Title in results. Too long: about 640 pixels, where results show about 580. Internal links. None to other items on this site. Search engines. Hidden from search engines when it’s published. The other search check passes.',
    ],
    'none pass' => [
        ['description' => ['text' => null, 'from' => null], 'titleWidth' => 640, 'document' => ['missingAlt' => 1], 'internalLinks' => 0, 'listing' => 'hidden'],
        'Description. None, and no excerpt. Search engines will choose text from the page. Title in results. Too long: about 640 pixels, where results show about 580. Alt text. 1 image in the body has no alt text. Internal links. None to other items on this site. Search engines. Hidden from search engines when it’s published.',
    ],
]);

it('names where the page’s description comes from, as the page’s head makes it (I-3124, I-290)', function (?string $from, string $says): void {
    expect(guardNode('core-checks.mjs', ['source' => $from])['says'])->toBe($says);
})->with([
    'the meta description' => ['meta', 'Description: the meta description.'],
    'the excerpt' => ['excerpt', 'Description: from the excerpt.'],
    'the tagline' => ['tagline', 'Description: from the site’s tagline.'],
    'none' => [null, 'Description: none. Search engines will choose text from the page.'],
]);
