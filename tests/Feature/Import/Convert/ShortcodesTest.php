<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\ConversionItem;
use App\Domain\Import\Convert\KnownTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

/*
 * WordPress's own media shortcodes (I-336): [caption] and [wp_caption],
 * [gallery], [embed], [audio], [video] and [playlist] as Teme's cards,
 * each where it stood.
 */

beforeEach(function (): void {
    $this->harbour = '01K5Z9P2QH7N3X4C8V6B0M1M41';
    $this->media = [
        1 => '01K5Z9P2QH7N3X4C8V6B0M1M01',
        2 => '01K5Z9P2QH7N3X4C8V6B0M1M02',
        3 => '01K5Z9P2QH7N3X4C8V6B0M1M03',
        4 => '01K5Z9P2QH7N3X4C8V6B0M1M04',
        5 => '01K5Z9P2QH7N3X4C8V6B0M1M05',
    ];
    $this->tide = '01K5Z9P2QH7N3X4C8V6B0M1A01';
    $this->bells = '01K5Z9P2QH7N3X4C8V6B0M1A02';
    $this->video = '01K5Z9P2QH7N3X4C8V6B0M1V01';
    $this->dawn = '01K5Z9P2QH7N3X4C8V6B0M1P01';
    $this->english = '01K5Z9P2QH7N3X4C8V6B0M1T01';
});

it('converts a caption shortcode in its newer form to an Image card, its caption after the image and its ID traced', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic('<p>[caption id="attachment_41" align="alignright" width="300"]<a href="https://old.example/wp-content/uploads/2024/05/harbour.jpg"><img class="size-medium" src="https://old.example/wp-content/uploads/2024/05/harbour-300x200.jpg" alt="The harbour" width="300" height="200" /></a> The harbour at six[/caption]</p>')], attachments: [41 => $this->harbour]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([WordPressContent::image($this->harbour, 'The harbour', 'The harbour at six')])
        ->and($conversion->messages)->toBe([]);
});

it('converts a caption shortcode in its older form, its caption an attribute holding a link', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic('<p>[caption id="attachment_41" align="alignnone" width="300" caption="Boats, by <a href=\'https://e.example/ada\'>Ada</a>"]<img src="https://old.example/wp-content/uploads/2024/05/harbour-300x200.jpg" alt="" />[/caption]</p>')], attachments: [41 => $this->harbour]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([WordPressContent::image($this->harbour, null, 'Boats, by Ada')])
        ->and(WordPressContent::said($conversion))->toBe([
            ['caption.links', 'Kept the words of 1 link in a caption, without the link: Teme’s captions are plain text.', ['count' => 1]],
        ]);
});

it('shows a caption shortcode with a width below 1 or no caption as its image alone, and one with no image as its words, as WordPress does', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic(
        '<p>[caption id="attachment_41" width="0"]<img src="https://old.example/wp-content/uploads/a.jpg" alt="A" /> Hidden words[/caption]</p>'
        .'<p>[wp_caption id="attachment_41" width="300"]<img src="https://old.example/wp-content/uploads/a.jpg" alt="B" />[/wp_caption]</p>'
        .'<p>[caption width="300"]Just words[/caption]</p>',
    )], attachments: [41 => $this->harbour]);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::image($this->harbour, 'A'),
        WordPressContent::image($this->harbour, 'B'),
        WordPressContent::p('Just words'),
    ]);
});

it('converts a caption whose image and words wpautop split into two paragraphs', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic("<p>[caption id=\"attachment_41\" width=\"300\"]<img src=\"https://old.example/wp-content/uploads/a.jpg\" alt=\"Boats\" /></p>\n<p>The harbour at six[/caption]</p>\n<p>After</p>\n")], attachments: [41 => $this->harbour]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::image($this->harbour, 'Boats', 'The harbour at six'),
        WordPressContent::p('After'),
    ]);
});

it('converts a gallery shortcode’s images to a Gallery card, in the order its IDs give', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic('<p>[gallery ids="3,1, 2" columns="2" link="file"]</p>')], attachments: $this->media);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        ['type' => 'gallery', 'attrs' => ['mediaIds' => [$this->media[3], $this->media[1], $this->media[2]], 'caption' => null, 'layout' => 'grid']],
    ]);
});

it('takes a gallery shortcode without IDs from the images attached to its post, as WordPress orders them', function (): void {
    $targets = new KnownTargets(attached: ['500:image' => [1, 2, 3], '77:image' => [4, 5]]);

    $conversion = WordPressContent::convert([WordPressContent::classic(
        "<p>[gallery]</p>\n<p>[gallery exclude=\"2\" order=\"DESC\"]</p>\n<p>[gallery include=\"3,1\"]</p>\n<p>[gallery id=\"77\"]</p>\n<p>[gallery id=\"9\"]</p>\n",
    )], $targets, new ConversionItem(500), attachments: $this->media);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => $node['attrs']['mediaIds'] ?? [], $conversion->document['content']))->toBe([
        [$this->media[1], $this->media[2], $this->media[3]],
        [$this->media[3], $this->media[1]],
        [$this->media[1], $this->media[3]],
        [$this->media[4], $this->media[5]],
    ])->and(WordPressContent::said($conversion))->toBe([
        ['gallery.empty', 'Left out 1 gallery shortcode with no images Teme can find.', ['count' => 1]],
    ]);
});

it('converts the embed, audio and video shortcodes to their cards', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic(
        "<p>[embed width=\"500\"]https://www.youtube.com/watch?v=abc123[/embed]</p>\n"
        ."<p>[audio mp3=\"https://old.example/wp-content/uploads/2024/05/tide.mp3\"][/audio]</p>\n"
        ."<p>[video src=\"https://old.example/wp-content/uploads/2024/05/harbour.mp4\" poster=\"https://old.example/wp-content/uploads/2024/05/dawn.jpg\" width=\"640\" height=\"360\" loop=\"on\" autoplay=\"1\" muted=\"true\"]<track src=\"https://old.example/wp-content/uploads/2024/05/harbour-en.vtt\" kind=\"captions\" srclang=\"en\" label=\"English\" default>[/video]</p>\n",
    )], files: [
        '2024/05/tide.mp3' => $this->tide,
        '2024/05/harbour.mp4' => $this->video,
        '2024/05/dawn.jpg' => $this->dawn,
        '2024/05/harbour-en.vtt' => $this->english,
    ]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::embed('https://www.youtube.com/watch?v=abc123', 'video', 'youtube'),
        ['type' => 'audio', 'attrs' => ['mediaId' => $this->tide, 'caption' => null, 'transcript' => null]],
        ['type' => 'video', 'attrs' => [
            'mediaId' => $this->video,
            'posterMediaId' => $this->dawn,
            'caption' => null,
            'videoWidth' => 640,
            'videoHeight' => 360,
            'loop' => true,
            'tracks' => [['mediaId' => $this->english, 'language' => 'en', 'kind' => 'captions', 'label' => 'English', 'default' => true]],
        ]],
    ])->and($conversion->messages)->toBe([]);
});

it('turns an embed shortcode of the old site’s own page into a paragraph linking to it, never an Embed card', function (): void {
    $article = '01k5z9p2qh7n3x4c8v6b0m1a01';

    $conversion = WordPressContent::convert(
        [WordPressContent::classic("<p>[embed]https://old.example/ferries/[/embed]</p>\n")],
        new KnownTargets(pages: ['/ferries' => ['kind' => 'content', 'id' => $article]]),
    );
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::linked('/ferries/', DocumentSchema::linkTo('content', $article))]));
});

it('turns a playlist shortcode into one card per file, from its IDs or its post’s attached files', function (): void {
    $conversion = WordPressContent::convert(
        [WordPressContent::classic("<p>[playlist ids=\"50,51\"]</p>\n<p>[playlist type=\"video\"]</p>\n")],
        new KnownTargets(attached: ['500:video' => [60]]),
        new ConversionItem(500),
        attachments: [50 => $this->tide, 51 => $this->bells, 60 => $this->video],
    );
    wpAccepted($conversion->document);

    expect(array_column($conversion->document['content'], 'type'))->toBe(['audio', 'audio', 'video'])
        ->and(array_column(array_column($conversion->document['content'], 'attrs'), 'mediaId'))->toBe([$this->tide, $this->bells, $this->video]);
});

it('puts the cards a shortcode makes where it stood, splitting the paragraph around it', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic("<p>Before<br />\n[gallery ids=\"1,2\"]<br />\nAfter</p>\n")], attachments: $this->media);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::p('Before'),
        ['type' => 'gallery', 'attrs' => ['mediaIds' => [$this->media[1], $this->media[2]], 'caption' => null, 'layout' => 'grid']],
        WordPressContent::p('After'),
    ]);
});
