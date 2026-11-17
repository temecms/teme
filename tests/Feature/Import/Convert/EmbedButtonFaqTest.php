<?php

declare(strict_types=1);

use App\Domain\Import\Phases\ImportMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Embeds, buttons and FAQ blocks (I-335): Teme's Embed and
 * Button cards, and FAQ blocks as the FAQ card.
 */

beforeEach(function (): void {
    $this->convert = function (array $blocks): array {
        $conversion = wpConvert($blocks);
        wpAccepted($conversion->document);

        return [
            wpWithoutIds($conversion->document)['content'],
            array_map(fn (ImportMessage $message): array => [$message->code, $message->context], $conversion->messages),
        ];
    };
    $this->embed = fn (string $url, string $kind, ?string $provider, ?string $caption = null): array => ['type' => 'embed', 'attrs' => [
        'url' => $url, 'kind' => $kind, 'provider' => $provider, 'title' => null, 'html' => null, 'thumbnailMediaId' => null, 'caption' => $caption,
    ]];
    $this->p = fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    $this->h3 = fn (array ...$inline): array => ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => $inline];
});

it('converts an embed to a card, framed when its provider is on the list', function (): void {
    [$content] = ($this->convert)([wpBlock(
        'core/embed',
        "<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc123\n</div><figcaption class=\"wp-element-caption\">A talk</figcaption></figure>",
        ['url' => 'https://www.youtube.com/watch?v=abc123', 'type' => 'video', 'providerNameSlug' => 'youtube'],
    )]);

    expect($content)->toBe([($this->embed)('https://www.youtube.com/watch?v=abc123', 'video', 'youtube', 'A talk')]);
});

it('converts an embed no provider frames to a link, and an old core-embed block from its wrapper', function (): void {
    [$content] = ($this->convert)([
        wpBlock('core/embed', '<figure class="wp-block-embed is-type-rich is-provider-twitter"><div class="wp-block-embed__wrapper">https://twitter.com/ada/status/1</div></figure>', ['url' => 'https://twitter.com/ada/status/1', 'providerNameSlug' => 'twitter']),
        wpBlock('core-embed/instagram', '<figure class="wp-block-embed-instagram wp-block-embed is-provider-instagram"><div class="wp-block-embed__wrapper">https://www.instagram.com/p/xyz/</div></figure>'),
    ]);

    expect($content)->toBe([
        ($this->embed)('https://twitter.com/ada/status/1', 'link', 'twitter'),
        ($this->embed)('https://www.instagram.com/p/xyz/', 'link', 'instagram'),
    ]);
});

it('leaves out an embed with no web address, saying so', function (): void {
    [$content, $said] = ($this->convert)([
        wpBlock('core/embed', '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper"></div></figure>'),
        wpBlock('core/embed', '<figure class="wp-block-embed"></figure>', ['url' => 'javascript:alert(1)']),
    ]);

    expect($content)->toBe([['type' => 'paragraph']])
        ->and($said)->toBe([['embed.no-address', ['count' => 2]]]);
});

it('converts buttons to Button cards, an outline one secondary, and a button to a page not imported yet to a linked paragraph', function (): void {
    [$content] = ($this->convert)([wpBlock('core/buttons', '<div class="wp-block-buttons">%s</div>', [], [
        wpBlock('core/button', '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://shop.example/buy">Buy now</a></div>'),
        wpBlock('core/button', '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact us</a></div>', ['className' => 'is-style-outline']),
    ])]);

    expect($content)->toBe([
        ['type' => 'button', 'attrs' => ['label' => 'Buy now', 'contentId' => null, 'url' => 'https://shop.example/buy', 'style' => 'primary']],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Contact us', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/contact/']]]]]],
    ]);
});

it('keeps a button with no address, or a label too long for the card, as a paragraph', function (): void {
    $long = str_repeat('Tell me more ', 7).'now';

    [$content] = ($this->convert)([
        wpBlock('core/button', '<div class="wp-block-button"><a class="wp-block-button__link">Soon</a></div>'),
        wpBlock('core/button', '<div class="wp-block-button"><button type="button" class="wp-block-button__link">Press</button></div>', ['tagName' => 'button']),
        wpBlock('core/button', "<div class=\"wp-block-button\"><a class=\"wp-block-button__link\" href=\"/more/\">{$long}</a></div>"),
    ]);

    expect($content)->toBe([
        ($this->p)('Soon'),
        ($this->p)('Press'),
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $long, 'marks' => [['type' => 'link', 'attrs' => ['href' => '/more/']]]]]],
    ]);
});

it('converts Yoast\'s FAQ block to the FAQ card', function (): void {
    [$content] = ($this->convert)([wpBlock('yoast/faq-block', '<div class="schema-faq wp-block-yoast-faq-block"><div class="schema-faq-section" id="faq-question-1"><strong class="schema-faq-question">Is it <em>free</em>?</strong> <p class="schema-faq-answer">Yes, <strong>always</strong>.</p> </div><div class="schema-faq-section" id="faq-question-2"><strong class="schema-faq-question">Where?</strong> <p class="schema-faq-answer">Online.</p> </div></div>')]);

    expect($content)->toBe([['type' => 'faq', 'attrs' => ['items' => [
        ['question' => 'Is it free?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Yes, '],
            ['type' => 'text', 'text' => 'always', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => '.'],
        ]]]]],
        ['question' => 'Where?', 'answer' => ['type' => 'doc', 'content' => [($this->p)('Online.')]]],
    ]]]]);
});

it('converts Rank Math\'s FAQ block to the FAQ card', function (): void {
    [$content] = ($this->convert)([wpBlock('rank-math/faq-block', '<div id="rank-math-faq" class="rank-math-block"><div class="rank-math-list "><div id="faq-question-1" class="rank-math-list-item"><h3 class="rank-math-question ">How long?</h3><div class="rank-math-answer "><p>Two days.</p><p>Sometimes three.</p></div></div></div></div>')]);

    expect($content)->toBe([['type' => 'faq', 'attrs' => ['items' => [
        ['question' => 'How long?', 'answer' => ['type' => 'doc', 'content' => [($this->p)('Two days.'), ($this->p)('Sometimes three.')]]],
    ]]]]);
});

it('keeps an FAQ block as headings and paragraphs when an answer holds more than text', function (): void {
    [$content] = ($this->convert)([wpBlock('rank-math/faq-block', '<div class="rank-math-list"><div class="rank-math-list-item"><h3 class="rank-math-question">Prices?</h3><div class="rank-math-answer"><table><tbody><tr><td>One</td></tr></tbody></table></div></div></div>')]);

    expect(array_column($content, 'type'))->toBe(['heading', 'table'])
        ->and($content[0])->toBe(($this->h3)(['type' => 'text', 'text' => 'Prices?']));
});

it('reads a FAQ block with no questions as any HTML', function (): void {
    [$content] = ($this->convert)([wpBlock('yoast/faq-block', '<div class="schema-faq wp-block-yoast-faq-block"><p>Just text</p></div>')]);

    expect($content)->toBe([($this->p)('Just text')]);
});
