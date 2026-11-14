<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\KnownTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Convert\WordPressContent;

uses(RefreshDatabase::class);

/*
 * Players and frames (I-446): WordPress's audio and video as
 * Teme's Audio and Video cards, a known player's frame as an Embed card,
 * each held where it stood, splitting the paragraph around it.
 */

beforeEach(function (): void {
    $this->tide = '01K5Z9P2QH7N3X4C8V6B0M1A01';
    $this->harbour = '01K5Z9P2QH7N3X4C8V6B0M1V01';
    $this->dawn = '01K5Z9P2QH7N3X4C8V6B0M1P01';
    $this->english = '01K5Z9P2QH7N3X4C8V6B0M1T01';
    $this->chapters = '01K5Z9P2QH7N3X4C8V6B0M1T02';
    $this->article = '01k5z9p2qh7n3x4c8v6b0m1a01';
    $this->video = fn (string $mediaId): array => ['type' => 'video', 'attrs' => [
        'mediaId' => $mediaId, 'posterMediaId' => null, 'caption' => null, 'videoWidth' => null, 'videoHeight' => null, 'loop' => false, 'tracks' => null,
    ]];
});

it('converts an audio block to an Audio card with its caption', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/audio', '<figure class="wp-block-audio"><audio controls src="https://old.example/wp-content/uploads/2024/05/tide.mp3"></audio><figcaption class="wp-element-caption">The <a href="https://e.example/">tide</a> report</figcaption></figure>', ['id' => 50])], attachments: [50 => $this->tide]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        ['type' => 'audio', 'attrs' => ['mediaId' => $this->tide, 'caption' => 'The tide report', 'transcript' => null]],
    ])->and(WordPressContent::said($conversion))->toBe([
        ['caption.links', 'Kept the words of 1 link in a caption, without the link: Teme’s captions are plain text.', ['count' => 1]],
    ]);
});

it('converts a video block with its poster, its size, loop mode and its caption tracks', function (): void {
    $conversion = WordPressContent::convert([wpBlock(
        'core/video',
        '<figure class="wp-block-video"><video autoplay controls loop muted poster="https://old.example/wp-content/uploads/2024/05/dawn.jpg" src="https://old.example/wp-content/uploads/2024/05/harbour.mp4" playsinline></video><figcaption class="wp-element-caption">Dawn</figcaption></figure>',
        ['id' => 60, 'width' => 1920, 'height' => 1080, 'tracks' => [
            ['src' => 'https://old.example/wp-content/uploads/2024/05/harbour-en.vtt', 'kind' => 'captions', 'srcLang' => 'en', 'label' => 'English', 'default' => true],
            ['src' => 'https://old.example/wp-content/uploads/2024/05/harbour-chapters.vtt', 'kind' => 'metadata', 'srcLang' => 'en'],
        ]],
    )], attachments: [60 => $this->harbour], files: [
        '2024/05/dawn.jpg' => $this->dawn,
        '2024/05/harbour-en.vtt' => $this->english,
        '2024/05/harbour-chapters.vtt' => $this->chapters,
    ]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([['type' => 'video', 'attrs' => [
        'mediaId' => $this->harbour,
        'posterMediaId' => $this->dawn,
        'caption' => 'Dawn',
        'videoWidth' => 1920,
        'videoHeight' => 1080,
        'loop' => true,
        'tracks' => [['mediaId' => $this->english, 'language' => 'en', 'kind' => 'captions', 'label' => 'English', 'default' => true]],
    ]]])->and(WordPressContent::said($conversion))->toBe([
        ['video.tracks', 'Left out 1 text track of a video that Teme can’t keep: a metadata track, or one without its file or language.', ['count' => 1]],
    ]);
});

it('keeps the playing switches only as loop mode, and says when it leaves one out', function (): void {
    $video = fn (string $switches): array => wpBlock('core/video', "<figure class=\"wp-block-video\"><video {$switches} controls src=\"https://old.example/wp-content/uploads/a.mp4\"></video></figure>", ['id' => 60]);

    $conversion = WordPressContent::convert([$video('autoplay'), $video('loop muted'), $video('preload="auto"')], attachments: [60 => $this->harbour]);

    expect(array_column(array_column($conversion->document['content'], 'attrs'), 'loop'))->toBe([false, false, false])
        ->and(WordPressContent::said($conversion))->toBe([
            ['video.playback', 'Left out the autoplay, loop or mute settings of 2 videos: Teme plays a video when the reader asks, or loops it silently.', ['count' => 2]],
        ]);
});

it('leaves out a sound or video Teme does not have, naming its file, and links one on another site', function (): void {
    $conversion = WordPressContent::convert([
        wpBlock('core/audio', '<figure class="wp-block-audio"><audio controls src="https://old.example/wp-content/uploads/2023/gone.mp3"></audio></figure>', ['id' => 99]),
        wpBlock('core/video', '<figure class="wp-block-video"><video controls src="https://videos.other.example/clips/boat.mp4"></video></figure>'),
    ]);
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [WordPressContent::linked('boat.mp4', 'https://videos.other.example/clips/boat.mp4')]))
        ->and(WordPressContent::said($conversion))->toBe([
            ['media.missing', 'Left out 1 sound or video that isn’t in Teme’s library: gone.mp3.', ['count' => 1, 'files' => ['gone.mp3']]],
            ['media.outside', 'Linked to 1 sound or video on another site instead of playing it: videos.other.example.', ['count' => 1, 'hosts' => ['videos.other.example']]],
        ]);
});

it('turns an audio element in classic text into a card where it stands, splitting its paragraph', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic("<p>Before</p>\n<p>Listen: <audio src=\"/wp-content/uploads/a.mp3\" controls></audio> then read on.</p>\n")], files: ['a.mp3' => $this->tide]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::p('Before'),
        WordPressContent::p('Listen:'),
        ['type' => 'audio', 'attrs' => ['mediaId' => $this->tide, 'caption' => null, 'transcript' => null]],
        WordPressContent::p('then read on.'),
    ]);
});

it('keeps a video in a list item inside the item, after its words', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/list', '<ul class="wp-block-list"><li>Clip <video src="https://old.example/wp-content/uploads/b.mp4"></video></li></ul>')], files: ['b.mp4' => $this->harbour]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
        WordPressContent::p('Clip'),
        ($this->video)($this->harbour),
    ]]]]])->and(wpEditorCheck(['list' => $conversion->document]))->toBe(['list' => null]);
});

it('turns a known player’s frame into an Embed card, and still removes any other frame', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic(
        '<p><iframe src="https://www.youtube-nocookie.com/embed/abc123?rel=0" width="560"></iframe></p>'
        .'<p><iframe src="https://player.vimeo.com/video/76979871?h=x"></iframe></p>'
        .'<p><iframe src="https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC"></iframe></p>'
        .'<p><iframe src="https://maps.example/embed?pb=1"></iframe></p>',
    )]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        WordPressContent::embed('https://www.youtube.com/watch?v=abc123', 'video', 'youtube'),
        WordPressContent::embed('https://vimeo.com/76979871', 'video', 'vimeo'),
        WordPressContent::embed('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC', 'audio', 'spotify'),
    ])->and(WordPressContent::said($conversion))->toBe([
        ['html.removed', 'Left out 1 iframe element, which Teme doesn’t keep in an article.', ['element' => 'iframe', 'count' => 1]],
    ]);
});

it('reads a lazy-loading plugin’s frame from its real address, and leaves its noscript copy with the noscript', function (): void {
    $conversion = WordPressContent::convert([WordPressContent::classic('<p><iframe src="about:blank" data-src="https://www.youtube.com/embed/abc123"></iframe></p><noscript><iframe src="https://www.youtube.com/embed/abc123"></iframe></noscript>')]);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([WordPressContent::embed('https://www.youtube.com/watch?v=abc123', 'video', 'youtube')])
        ->and($conversion->messages)->toBe([]);
});

it('splits a heading where a player’s frame stands inside it', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/heading', '<h2 class="wp-block-heading">Watch the crossing<iframe src="https://www.youtube.com/embed/abc123"></iframe></h2>', ['level' => 2])]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Watch the crossing']]],
        WordPressContent::embed('https://www.youtube.com/watch?v=abc123', 'video', 'youtube'),
    ]);
});

it('converts media and text’s video to a Video card before its words', function (): void {
    $conversion = WordPressContent::convert([wpBlock(
        'core/media-text',
        '<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><video controls src="https://old.example/wp-content/uploads/m.mp4"></video></figure><div class="wp-block-media-text__content">%s</div></div>',
        ['mediaId' => 61, 'mediaType' => 'video'],
        [wpBlock('core/paragraph', '<p>Beside it</p>')],
    )], attachments: [61 => $this->harbour]);
    wpAccepted($conversion->document);

    expect(wpWithoutIds($conversion->document)['content'])->toBe([($this->video)($this->harbour), WordPressContent::p('Beside it')])
        ->and($conversion->messages)->toBe([]);
});

it('counts the scripts on the elements it keeps', function (): void {
    $conversion = WordPressContent::convert([wpBlock('core/paragraph', '<p onclick="steal()">Hi <a href="https://e.example/" onmouseover="track()">there</a></p>')]);

    expect(WordPressContent::said($conversion))->toBe([
        ['html.event-attributes', 'Left out the scripts on 2 elements: Teme doesn’t run scripts in an article.', ['count' => 2]],
    ]);
});

it('never keeps the old site’s own address in an Embed card: an embed of its own post is a paragraph linking to it', function (): void {
    $embed = fn (string $url): array => wpBlock('core/embed', "<figure class=\"wp-block-embed is-type-wp-embed\"><div class=\"wp-block-embed__wrapper\">{$url}</div></figure>", ['url' => $url, 'type' => 'wp-embed', 'providerNameSlug' => 'old-example']);

    $conversion = WordPressContent::convert(
        [$embed('https://old.example/ferries/'), $embed('https://www.old.example/later-story/')],
        new KnownTargets(pages: ['/ferries' => ['kind' => 'content', 'id' => $this->article]]),
    );
    wpAccepted($conversion->document);

    expect(array_map(fn (array $node): array => wpWithoutIds($node), $conversion->document['content']))->toBe(array_map(fn (array $node): array => wpWithoutIds($node), [
        WordPressContent::linked('/ferries/', DocumentSchema::linkTo('content', $this->article)),
        WordPressContent::linked('/later-story/', '/later-story/'),
    ]))->and($conversion->pendingLinks)->toBe(['/later-story/']);
});

it('makes players and frames the server keeps and the editor’s own schema accepts', function (): void {
    $documents = array_map(fn (array $blocks): array => WordPressContent::convert($blocks, attachments: [50 => $this->tide, 60 => $this->harbour])->document, [
        'audio' => [wpBlock('core/audio', '<figure class="wp-block-audio"><audio src="https://old.example/a.mp3"></audio></figure>', ['id' => 50])],
        'video' => [wpBlock('core/video', '<figure class="wp-block-video"><video loop autoplay muted src="https://old.example/v.mp4"></video></figure>', ['id' => 60])],
        'frame in a paragraph' => [WordPressContent::classic('<p>See <iframe src="https://player.vimeo.com/video/1"></iframe> here</p>')],
    ]);

    foreach ($documents as $document) {
        wpAccepted($document);
    }

    expect(wpEditorCheck($documents))->toBe(array_fill_keys(array_keys($documents), null));
});
