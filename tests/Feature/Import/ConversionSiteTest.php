<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\BlockConverter;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\KnownTargets;
use App\Domain\Import\Convert\Media\KnownMedia;
use App\Domain\Import\Phases\SecondPass\SecondPassDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

it('treats a listed staging host as the site’s own page', function (): void {
    $site = new ConversionSite('https://example.com', otherHosts: ['stage.example.com']);

    expect($site->pageOf('https://stage.example.com/a-post/'))->toBe([
        'path' => '/a-post/',
        'query' => '',
        'place' => '',
    ])->and($site->hostKind('stage.example.com'))->toBe('other')
        ->and($site->hostKind('example.com'))->toBe('home')
        ->and($site->hostKind('other.org'))->toBeNull();
});

it('converts links and images on another address of this site', function (): void {
    $article = '01k5z9p2qh7n3x4c8v6b0m1a01';
    $mediaId = '01K5Z9P2QH7N3X4C8V6B0M1F01';
    $site = new ConversionSite('https://old.example', otherHosts: ['stage.example.com']);
    $targets = new KnownTargets(pages: [
        '/a-post' => ['kind' => 'content', 'id' => $article],
    ]);
    $media = new KnownMedia(files: ['2026/01/a.jpg' => $mediaId]);

    $conversion = app(BlockConverter::class)->convert([
        WordPressContent::classic(
            '<p><a href="https://stage.example.com/a-post/">Post</a></p>'
            .'<p><a href="http://www.stage.example.com/a-post/">Http</a></p>'
            .'<p><a href="/a-post/">Relative</a></p>'
            .'<p><a href="https://example.org/elsewhere/">Outside</a></p>'
            .'<p><img src="https://stage.example.com/wp-content/uploads/2026/01/a.jpg" alt="A"></p>'
        ),
    ], $site, $media, $targets);

    $hrefs = array_map(
        fn (array $paragraph): ?string => $paragraph['content'][0]['marks'][0]['attrs']['href']
            ?? ($paragraph['type'] === 'image' ? $paragraph['attrs']['mediaId'] ?? null : null),
        $conversion->document['content'],
    );

    expect($hrefs)->toBe([
        DocumentSchema::linkTo('content', $article),
        DocumentSchema::linkTo('content', $article),
        DocumentSchema::linkTo('content', $article),
        'https://example.org/elsewhere/',
        $mediaId,
    ])->and(array_column(WordPressContent::said($conversion), 0))->toContain('links.other-host', 'images.other-host');
});

it('leaves a not-yet-imported target on another host for the second pass', function (): void {
    $site = new ConversionSite('https://old.example', otherHosts: ['stage.example.com']);
    $conversion = app(BlockConverter::class)->convert([
        WordPressContent::classic('<p><a href="https://stage.example.com/later-story/">Later</a></p>'),
    ], $site, new KnownMedia, new KnownTargets);

    expect($conversion->pendingLinks)->toBe(['/later-story/'])
        ->and($conversion->document['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe('/later-story/');
});

it('leaves a sibling host outside when only another address is listed', function (): void {
    $site = new ConversionSite('https://example.com', otherHosts: ['stage.example.com']);
    $conversion = app(BlockConverter::class)->convert([
        WordPressContent::classic(
            '<p><a href="https://dev.example.com/draft/">Draft</a></p>'
            .'<p><img src="https://dev.example.com/wp-content/uploads/2026/01/a.jpg" alt="A"></p>'
        ),
    ], $site, new KnownMedia, new KnownTargets);

    $href = $conversion->document['content'][0]['content'][0]['marks'][0]['attrs']['href'] ?? null;
    $src = $conversion->document['content'][1]['attrs']['src']
        ?? $conversion->document['content'][1]['content'][0]['marks'][0]['attrs']['href']
        ?? $conversion->document['content'][1]['attrs']['mediaId']
        ?? null;
    $said = array_column(WordPressContent::said($conversion), 0);

    expect($href)->toBe('https://dev.example.com/draft/')
        ->and($src)->toBe('https://dev.example.com/wp-content/uploads/2026/01/a.jpg')
        ->and($said)->not->toContain('links.other-host', 'images.other-host');
});

it('leaves a link in an FAQ answer to a page not imported yet for the second pass, which finishes it by ID', function (): void {
    $conversion = wpConvert([wpBlock('yoast/faq-block', '<div class="schema-faq wp-block-yoast-faq-block"><div class="schema-faq-section"><strong class="schema-faq-question">Where?</strong> <p class="schema-faq-answer">See <a href="https://old.example/later-story/">later</a>.</p> </div></div>')]);
    $answer = fn (array $content): mixed => $content[0]['attrs']['items'][0]['answer']['content'][0]['content'][1]['marks'][0]['attrs']['href'] ?? null;

    $done = SecondPassDocument::relink($conversion->document['content'], ['/later-story/' => 'teme:content/01k5z0f1ed0000000000000abc']);

    expect($conversion->pendingLinks)->toBe(['/later-story/'])
        ->and($answer($conversion->document['content']))->toBe('/later-story/')
        ->and($answer($done))->toBe('teme:content/01k5z0f1ed0000000000000abc');
});
