<?php

declare(strict_types=1);

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Cards\PlainText;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->audio = avUpload($this->author, avAudioFile(), 'Tide report.mp3');
    $this->render = fn (array ...$nodes): string => app(DocumentRenderer::class)->html(docWith(...$nodes));
});

it('renders the audio fixture to its expected HTML (I-446)', function (): void {
    // The fixture's file under the ID its JSON names, so the golden HTML doesn't depend on an upload's random ID.
    DB::table('media')->where('id', $this->audio->id)->update([
        'id' => '01K6A0D1000000000000000AD1',
        'original_path' => '01K6A0D1000000000000000AD1/v1/tide-report.mp3',
    ]);
    $document = json_decode((string) file_get_contents(base_path('tests/Fixtures/Documents/audio.json')), true);

    expect(app(DocumentRenderer::class)->html($document))
        ->toBe(trim((string) file_get_contents(base_path('tests/Fixtures/Documents/audio.html'))));
});

it('accepts the fixture as the editor writes it, and a card with no file only in an autosave (I-446)', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/Documents/audio.json')), true);
    $unfinished = docWith(['type' => 'audio', 'attrs' => ['id' => '01K6A0D1000000000000000AN2', 'mediaId' => null, 'caption' => null, 'transcript' => null]]);

    expect(fn () => docValidate($fixture))->not->toThrow(Throwable::class)
        ->and(fn () => app(DocumentValidator::class)->validate($unfinished, allowUnfinishedCards: true))->not->toThrow(Throwable::class)
        ->and(fn () => docValidate($unfinished))->toThrow(InvalidDocumentException::class);
});

it('refuses a transcript over 100,000 characters (I-446)', function (): void {
    $long = avAudioNode($this->audio, ['transcript' => str_repeat('a', 100_001)]);

    expect(fn () => docValidate(docWith(avAudioNode($this->audio, ['transcript' => str_repeat('a', 100_000)]))))->not->toThrow(Throwable::class)
        ->and(fn () => docValidate(docWith($long)))->toThrow(InvalidDocumentException::class);
});

it('draws nothing for a sound file that has gone, is in the trash, or isn\'t sound (I-446)', function (string $case): void {
    $node = match ($case) {
        'gone' => avAudioNode($this->audio, ['mediaId' => '01K6A0D1000000000000000ZZ9']),
        'in the trash' => (function (): array {
            app(TrashMedia::class)->execute(new MediaActionInput($this->audio->id, (string) $this->editor->id));

            return avAudioNode($this->audio);
        })(),
        'a video' => avAudioNode(avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4')),
        default => avAudioNode(pageImage($this->author, 800, 600)),
    };

    expect(($this->render)($node))->toBe('');
})->with(['gone', 'in the trash', 'a video', 'an image']);

it('draws a transcript as paragraphs, escaped, from any line endings, with no empty paragraph (I-446)', function (): void {
    $html = ($this->render)(avAudioNode($this->audio, [
        'transcript' => "\r\n  Q: Is <b>high water</b> at 6?\r\nA: \"Yes\" & no.\r\n\r\n\r\n   \r\nThe end.\r\n\r\n",
    ]));

    expect($html)->toContain('<details class="card-audio__transcript"><summary>Transcript</summary><p>Q: Is &lt;b&gt;high water&lt;/b&gt; at 6?<br>A: &quot;Yes&quot; &amp; no.</p><p>The end.</p></details>')
        ->not->toContain('<p></p>');
});

it('leaves out the caption and the transcript when there are none, or only blank lines (I-446)', function (string $transcript): void {
    $html = ($this->render)(avAudioNode($this->audio, ['caption' => '  ', 'transcript' => $transcript]));

    expect($html)->toBe('<div class="card-audio"><figure><audio src="'.e(Storage::disk('media')->url($this->audio->original_path)).'" controls preload="metadata"></audio></figure></div>');
})->with(['nothing' => [''], 'blank lines' => ["\n\n \n"]]);

it('gives its caption and its transcript as its words, so search finds what was said (I-446)', function (): void {
    $document = docWith(avAudioNode($this->audio, ['caption' => 'The tide report', 'transcript' => "Good morning.\n\nHigh water is at six."]));

    expect(PlainText::of($document))->toBe('The tide report Good morning. High water is at six.');
});

it('records its file as used by the article, so the library knows where it is (I-446)', function (): void {
    $item = lifecycleDraft($this->author, 'Harbour news');
    docSave($item, $this->author, docWith(avAudioNode($this->audio)));

    expect(refsUses($this->audio))->toBe([1, 0]);
});

it('says how it changed between two versions (I-446)', function (array $before, array $after, string $line): void {
    $card = app(CardRegistry::class)->find('audio');

    expect($card?->describeChange($before, $after))->toBe($line);
})->with([
    'a new file' => [['mediaId' => 'A'], ['mediaId' => 'B'], 'Audio — audio replaced'],
    'a caption and a transcript added' => [['caption' => null, 'transcript' => null], ['caption' => 'Tides', 'transcript' => 'Hello.'], 'Audio — caption changed and transcript added'],
    'a transcript removed' => [['transcript' => 'Hello.'], ['transcript' => ''], 'Audio — transcript removed'],
    'a transcript edited' => [['transcript' => 'Hello.'], ['transcript' => 'Hello there.'], 'Audio — transcript changed'],
]);

it('links its style only on a page that draws it (I-248)', function (): void {
    pageArticleFromDocument($this->author, 'Tide report', [avAudioNode($this->audio)]);
    pageArticleFromDocument($this->author, 'Quiet day', [docText('Nothing to hear.')]);

    expect(addrGet('/tide-report/')->assertOk()->getContent())->toContain('data-teme-card="audio"')
        ->and(addrGet('/quiet-day/')->assertOk()->getContent())->not->toContain('data-teme-card="audio"');
});
