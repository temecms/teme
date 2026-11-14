<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Import\Phases\Content\WordPressPost;
use Carbon\CarbonImmutable;

it('takes each WordPress status to Teme’s, private among the published and a password beside any (I-1700, I-1709)', function (array $item, ?array $expected): void {
    expect(WordPressPost::status($item))->toBe($expected);
})->with([
    'published' => [['status' => 'publish', 'password' => ''], ['status' => 'published', 'visibility' => null, 'trashed' => false, 'dropped' => false]],
    'scheduled' => [['status' => 'future'], ['status' => 'scheduled', 'visibility' => null, 'trashed' => false, 'dropped' => false]],
    'a draft' => [['status' => 'draft'], ['status' => 'draft', 'visibility' => null, 'trashed' => false, 'dropped' => false]],
    'pending review' => [['status' => 'pending'], ['status' => 'review', 'visibility' => null, 'trashed' => false, 'dropped' => false]],
    'private' => [['status' => 'private'], ['status' => 'published', 'visibility' => 'private', 'trashed' => false, 'dropped' => false]],
    'published behind a password' => [['status' => 'publish', 'password' => 'harbour'], ['status' => 'published', 'visibility' => 'protected', 'trashed' => false, 'dropped' => false]],
    'a draft behind a password' => [['status' => 'draft', 'password' => 'harbour'], ['status' => 'draft', 'visibility' => 'protected', 'trashed' => false, 'dropped' => false]],
    'a password of spaces only' => [['status' => 'publish', 'password' => '   '], ['status' => 'published', 'visibility' => null, 'trashed' => false, 'dropped' => false]],
    'private with a password too' => [['status' => 'private', 'password' => 'harbour'], ['status' => 'published', 'visibility' => 'private', 'trashed' => false, 'dropped' => true]],
    'trashed after publishing' => [['status' => 'trash', 'status_before_trash' => 'publish'], ['status' => 'published', 'visibility' => null, 'trashed' => true, 'dropped' => false]],
    'trashed while scheduled' => [['status' => 'trash', 'status_before_trash' => 'future'], ['status' => 'draft', 'visibility' => null, 'trashed' => true, 'dropped' => false]],
    'trashed while private' => [['status' => 'trash', 'status_before_trash' => 'private'], ['status' => 'published', 'visibility' => 'private', 'trashed' => true, 'dropped' => false]],
    'trashed, its status before unknown' => [['status' => 'trash'], ['status' => 'draft', 'visibility' => null, 'trashed' => true, 'dropped' => false]],
    'an auto-draft' => [['status' => 'auto-draft'], null],
    'an attachment’s inherit' => [['status' => 'inherit'], null],
]);

it('keeps a password only for an item it protects, exactly as WordPress kept it (I-1700)', function (array $item, ?string $expected): void {
    expect(WordPressPost::password($item))->toBe($expected);
})->with([
    'published behind one' => [['status' => 'publish', 'password' => ' Harbour 2024 '], ' Harbour 2024 '],
    'none' => [['status' => 'publish', 'password' => ''], null],
    'spaces only' => [['status' => 'publish', 'password' => '   '], null],
    'private, so not kept' => [['status' => 'private', 'password' => 'harbour'], null],
    'not text' => [['status' => 'publish', 'password' => 2024], null],
]);

it('keeps a title as plain words, at most 512 characters, and calls an empty one Untitled', function (): void {
    expect(WordPressPost::title(['title' => '  <em>Night</em> &amp; day:   the   &lt;tide&gt; ']))->toBe(['title' => 'Night & day: the <tide>', 'untitled' => false, 'shortened' => false])
        ->and(WordPressPost::title(['title' => '<br>']))->toBe(['title' => 'Untitled', 'untitled' => true, 'shortened' => false])
        ->and(WordPressPost::title(['title' => str_repeat('é', 600)]))->toBe(['title' => str_repeat('é', 512), 'untitled' => false, 'shortened' => true]);
});

it('keeps WordPress’s slug decoded, without its trash suffix, and makes one from the title when WordPress never named it (I-397)', function (): void {
    expect(WordPressPost::slug(['slug' => 'caf%c3%a9-news'], 'Café news'))->toBe('café-news')
        ->and(WordPressPost::slug(['slug' => 'harbour-news__trashed'], 'Harbour news'))->toBe('harbour-news')
        ->and(WordPressPost::slug(['slug' => 'tide_tables'], 'Tide tables'))->toBe('tide-tables')
        ->and(WordPressPost::slug(['slug' => ''], 'A draft never named'))->toBe('a-draft-never-named');
});

it('reads the published instant from GMT, and from the local date in the site’s timezone where WordPress kept none', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');

    $published = WordPressPost::dates(['status' => 'publish', 'date' => '2019-05-04 00:30:00', 'date_gmt' => '2019-05-03 22:30:00', 'modified_gmt' => '2019-05-04 08:00:00'], 'Europe/Berlin', $now);
    $floating = WordPressPost::dates(['status' => 'publish', 'date' => '2019-05-04 00:30:00', 'date_gmt' => '0000-00-00 00:00:00', 'modified_gmt' => ''], 'Europe/Berlin', $now);
    $draft = WordPressPost::dates(['status' => 'draft', 'date' => '2026-10-01 09:00:00', 'date_gmt' => '0000-00-00 00:00:00', 'modified_gmt' => '2026-10-02 10:00:00'], 'UTC', $now);
    $undated = WordPressPost::dates(['status' => 'draft'], 'UTC', $now);

    expect($published['published']?->toIso8601String())->toBe('2019-05-03T22:30:00+00:00')
        ->and($published['modified']->toIso8601String())->toBe('2019-05-04T08:00:00+00:00')
        ->and($floating['published']?->toIso8601String())->toBe('2019-05-03T22:30:00+00:00')
        ->and($floating['modified']->toIso8601String())->toBe('2019-05-03T22:30:00+00:00')
        ->and($draft['published'])->toBeNull()
        ->and($draft['created']->toIso8601String())->toBe('2026-10-01T09:00:00+00:00')
        ->and($draft['modified']->toIso8601String())->toBe('2026-10-02T10:00:00+00:00')
        ->and($undated['created']->equalTo($now))->toBeTrue();
});

it('reads WordPress’s trash time, or now when it sent none or one ahead of now', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');

    expect(WordPressPost::trashedAt(['status' => 'trash', 'trashed_at' => 1759309200], $now)?->toIso8601String())->toBe('2025-10-01T09:00:00+00:00')
        ->and(WordPressPost::trashedAt(['status' => 'trash'], $now)?->equalTo($now))->toBeTrue()
        ->and(WordPressPost::trashedAt(['status' => 'trash', 'trashed_at' => 4102444800], $now)?->equalTo($now))->toBeTrue()
        ->and(WordPressPost::trashedAt(['status' => 'publish', 'trashed_at' => 1759309200], $now))->toBeNull();
});

it('opens comments only where WordPress had them open, and keeps the excerpt as plain text of at most 1,000 characters', function (): void {
    expect(WordPressPost::discussion(['comment_status' => 'open']))->toBe(Discussion::Open)
        ->and(WordPressPost::discussion(['comment_status' => 'closed']))->toBe(Discussion::Closed)
        ->and(WordPressPost::discussion([]))->toBe(Discussion::Closed)
        ->and(WordPressPost::excerpt(['excerpt' => '<p>The ferries &amp; the tide.</p>']))->toBe(['text' => 'The ferries & the tide.', 'shortened' => false])
        ->and(WordPressPost::excerpt(['excerpt' => '']))->toBe(['text' => null, 'shortened' => false])
        ->and(WordPressPost::excerpt(['excerpt' => str_repeat('é', 1200)]))->toBe(['text' => str_repeat('é', 1000), 'shortened' => true]);
});

it('keeps WordPress’s own address in the path table’s form, its folder too, and none with a query', function (): void {
    expect(WordPressPost::path(['permalink' => 'https://old.example/2019/05/04/Caf%C3%A9-News/']))->toBe('2019/05/04/café-news')
        ->and(WordPressPost::path(['permalink' => 'https://old.example/blog/about/team/']))->toBe('blog/about/team')
        ->and(WordPressPost::path(['permalink' => 'https://old.example/?p=12']))->toBeNull()
        ->and(WordPressPost::path(['permalink' => 'https://old.example/']))->toBe('')
        ->and(WordPressPost::path([]))->toBeNull();
});

it('keeps old slugs and old dates once each, as Teme reads them, never the current slug or a date that isn’t one', function (): void {
    $item = ['old_slugs' => ['harbor-news', 'harbour-news', 'harbor-news', 'caf%c3%a9', 42], 'old_dates' => ['2019-04-30', '2019-04-30', '2019-02-30', 'yesterday', '2019-05-01 10:00:00']];

    expect(WordPressPost::oldSlugs($item, 'harbour-news'))->toBe(['harbor-news', 'café'])
        ->and(WordPressPost::oldDates($item))->toBe(['2019-04-30', '2019-05-01']);
});

it('keeps WordPress’s guid as it is, and never makes one up (I-922)', function (): void {
    expect(WordPressPost::guid(['guid' => ' http://old.example/?p=12 ']))->toBe('http://old.example/?p=12')
        ->and(WordPressPost::guid(['guid' => '']))->toBeNull()
        ->and(WordPressPost::guid([]))->toBeNull()
        ->and(WordPressPost::guid(['guid' => 'http://old.example/?p='.str_repeat('1', 300)]))->toBeNull();
});

it('lists names as people read them', function (): void {
    expect(WordPressPost::listed(['title']))->toBe('title')
        ->and(WordPressPost::listed(['title', 'excerpt']))->toBe('title and excerpt')
        ->and(WordPressPost::listed(['title', 'excerpt', 'SEO title']))->toBe('title, excerpt and SEO title');
});
