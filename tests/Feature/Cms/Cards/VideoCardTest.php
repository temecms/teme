<?php

declare(strict_types=1);

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Cards\PlainText;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->video = avUpload($this->author, avVideoFile(), 'Harbour dawn.mp4');
    $this->captions = avUpload($this->author, avCaptionsFile(), 'Harbour dawn.vtt');
    $this->render = fn (array ...$nodes): string => app(DocumentRenderer::class)->html(docWith(...$nodes));
    $this->url = fn (Media $file): string => MediaDisks::public()->url($file->refresh()->original_path);
    $this->track = fn (Media $file, array $attrs = []): array => ['mediaId' => $file->id, 'language' => 'en', 'kind' => 'captions', 'label' => 'English', 'default' => false, ...$attrs];
    $this->refusal = function (array $document): ?string {
        try {
            docValidate($document);
        } catch (InvalidDocumentException $refusal) {
            return ($refusal->path === '' ? '' : "{$refusal->path}: ").$refusal->errors()['document'][0];
        }

        return null;
    };
});

it('renders the video fixture to its expected HTML (I-446)', function (): void {
    $poster = mediaUpload($this->author, mediaJpeg(1200, 800), 'harbour.jpg', allowDuplicate: true);
    $variants = [];

    foreach (['thumb' => 300, 'w640' => 640, 'w1280' => 1200] as $key => $width) {
        $variants[$key] = ['path' => "01K6A0D1000000000000000PT1/v1/harbour-{$key}.webp", 'width' => $width, 'height' => intdiv($width * 2, 3), 'bytes' => 1000];
    }

    // The fixture's files under the IDs its JSON names, so the golden HTML doesn't depend on an upload's random ID.
    foreach ([
        [$this->video->id, '01K6A0D1000000000000000VD1', ['original_path' => '01K6A0D1000000000000000VD1/v1/harbour-dawn.mp4']],
        [$this->captions->id, '01K6A0D1000000000000000VT1', ['original_path' => '01K6A0D1000000000000000VT1/v1/harbour-dawn.vtt']],
        [$poster->id, '01K6A0D1000000000000000PT1', ['variants' => json_encode($variants)]],
    ] as [$from, $to, $columns]) {
        DB::table('media')->where('id', $from)->update(['id' => $to, ...$columns]);
    }

    $document = json_decode((string) file_get_contents(base_path('tests/Fixtures/Documents/video.json')), true);

    expect(app(DocumentRenderer::class)->html($document))
        ->toBe(trim((string) file_get_contents(base_path('tests/Fixtures/Documents/video.html'))));
});

it('accepts the fixture as the editor writes it (I-446)', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/Documents/video.json')), true);

    expect(($this->refusal)($fixture))->toBeNull();
});

it('plays in loop mode on its own, silently, again and again, with no controls (I-1716)', function (): void {
    $html = ($this->render)(avVideoNode($this->video, ['loop' => true]));

    expect($html)->toBe('<figure class="card-video card-video--loop"><video src="'.e(($this->url)($this->video)).'" autoplay loop muted preload="metadata" playsinline></video></figure>');
});

it('draws a size only when both sides are known (I-446)', function (string $case): void {
    $sides = match ($case) {
        'both' => ['videoWidth' => 1080, 'videoHeight' => 1920],
        'the width only' => ['videoWidth' => 1080],
        default => [],
    };
    $html = ($this->render)(avVideoNode($this->video, $sides));

    expect(str_contains($html, ' width="1080" height="1920"'))->toBe($case === 'both')
        ->and(str_contains($html, 'width="1080"'))->toBe($case === 'both');
})->with(['both', 'the width only', 'neither']);

it('refuses a side of 0, over 16,384 pixels, or not a whole number (I-446)', function (int|float $side): void {
    expect(($this->refusal)(docWith(avVideoNode($this->video, ['videoWidth' => $side, 'videoHeight' => 1080]))))
        ->toStartWith('content.0.attrs.videoWidth: ');
})->with(['nothing' => [0], 'too wide' => [16385], 'a fraction' => [12.5]]);

it('draws the poster at its width nearest 1,280 pixels, and none for a poster that has gone or isn\'t an image (I-446)', function (): void {
    $poster = pageImage($this->author, 1600, 900);
    $variants = [];

    foreach (['thumb' => 300, 'w640' => 640, 'w1280' => 1200, 'w1600' => 1600] as $key => $width) {
        $variants[$key] = ['path' => "{$poster->id}/v1/harbour-{$key}.webp", 'width' => $width, 'height' => intdiv($width * 9, 16), 'bytes' => 1000];
    }

    DB::table('media')->where('id', $poster->id)->update(['variants' => json_encode($variants)]);

    expect(($this->render)(avVideoNode($this->video, ['posterMediaId' => $poster->id])))->toContain(' poster="'.MediaDisks::public()->url("{$poster->id}/v1/harbour-w1280.webp").'"')
        ->and(($this->render)(avVideoNode($this->video, ['posterMediaId' => '01K6A0D1000000000000000ZZ9'])))->not->toContain('poster=')
        ->and(($this->render)(avVideoNode($this->video, ['posterMediaId' => $this->captions->id])))->not->toContain('poster=');
});

it('draws each caption track in the writer\'s order, with its kind, language, label and default (I-446)', function (): void {
    $french = avUpload($this->author, avCaptionsFile('La marée monte.'), 'Harbour dawn fr.vtt');
    $html = ($this->render)(avVideoNode($this->video, ['tracks' => [
        ($this->track)($this->captions, ['default' => true]),
        ($this->track)($french, ['kind' => 'subtitles', 'language' => 'fr', 'label' => null]),
    ]]));

    expect($html)->toContain(
        '<track kind="captions" src="'.e(($this->url)($this->captions)).'" srclang="en" label="English" default>'
        .'<track kind="subtitles" src="'.e(($this->url)($french)).'" srclang="fr"></video>'
    );
});

it('skips a caption track whose file has gone, is in the trash or isn\'t WebVTT, and still plays the video (I-446)', function (string $case): void {
    $file = match ($case) {
        'gone' => null,
        'in the trash' => (function (): Media {
            app(TrashMedia::class)->execute(new MediaActionInput($this->captions->id, (string) $this->editor->id));

            return $this->captions;
        })(),
        'a PDF' => avUpload($this->author, mediaPdf(), 'Programme.pdf'),
        default => (function (): Media {
            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-'.Str::random(12).'.txt';
            file_put_contents($path, "Just some notes.\n");

            return avUpload($this->author, $path, 'notes.txt');
        })(),
    };
    $track = $file === null ? ($this->track)($this->captions, ['mediaId' => '01K6A0D1000000000000000ZZ9']) : ($this->track)($file);

    $html = ($this->render)(avVideoNode($this->video, ['tracks' => [$track]]));

    expect($html)->toContain('<video src="'.e(($this->url)($this->video)).'" controls')
        ->not->toContain('<track');
})->with(['gone', 'in the trash', 'a PDF', 'plain text']);

it('draws the video without tracks when its tracks aren\'t a list, as a pasted card or an autosave may hold them (I-446)', function (mixed $tracks): void {
    $html = ($this->render)(avVideoNode($this->video, ['tracks' => $tracks]));

    expect($html)->toContain('<video src="')->not->toContain('<track');
})->with([
    'empty words' => [''],
    'a pasted object' => ['[object Object]'],
    'a list of words' => [['01K6A0D1000000000000000VT1']],
    'one track not in a list' => [['mediaId' => '01K6A0D1000000000000000VT1', 'language' => 'en', 'kind' => 'captions', 'label' => null, 'default' => false]],
]);

it('refuses a track it can\'t draw, saying which (I-446)', function (string $case): void {
    $tracks = match ($case) {
        'nine tracks' => array_fill(0, 9, ($this->track)($this->captions)),
        'a kind for scripts' => [($this->track)($this->captions, ['kind' => 'metadata'])],
        'a language that isn\'t a tag' => [($this->track)($this->captions, ['language' => 'English'])],
        'a key it doesn\'t know' => [($this->track)($this->captions, ['src' => '/storage/captions.vtt'])],
        'no file' => [array_diff_key(($this->track)($this->captions), ['mediaId' => true])],
        default => [($this->track)($this->captions, ['default' => true]), ($this->track)($this->captions, ['default' => true, 'language' => 'fr'])],
    };
    $expected = match ($case) {
        'nine tracks' => 'content.0.attrs.tracks: ',
        'a kind for scripts' => 'content.0.attrs.tracks.0.kind: ',
        'a language that isn\'t a tag' => 'content.0.attrs.tracks.0.language: ',
        'a key it doesn\'t know' => 'content.0.attrs.tracks.0: ',
        'no file' => 'content.0.attrs.tracks.0.mediaId: ',
        default => 'content.0.attrs.tracks: Only one caption track can be on by default.',
    };

    expect(($this->refusal)(docWith(avVideoNode($this->video, ['tracks' => $tracks]))))->toStartWith($expected);
})->with(['nine tracks', 'a kind for scripts', 'a language that isn\'t a tag', 'a key it doesn\'t know', 'no file', 'two on by default']);

it('accepts language tags with a region or a script, and a track with no label (I-446)', function (string $language): void {
    expect(($this->refusal)(docWith(avVideoNode($this->video, ['tracks' => [($this->track)($this->captions, ['language' => $language, 'label' => null])]]))))->toBeNull();
})->with(['en', 'pt-BR', 'zh-Hant', 'sr-Latn-RS']);

it('draws nothing for a video file that has gone, is in the trash, or isn\'t video (I-446)', function (string $case): void {
    $node = match ($case) {
        'gone' => avVideoNode($this->video, ['mediaId' => '01K6A0D1000000000000000ZZ9']),
        'in the trash' => (function (): array {
            app(TrashMedia::class)->execute(new MediaActionInput($this->video->id, (string) $this->editor->id));

            return avVideoNode($this->video);
        })(),
        default => avVideoNode(avUpload($this->author, avAudioFile(), 'Tide report.mp3')),
    };

    expect(($this->render)($node))->toBe('');
})->with(['gone', 'in the trash', 'a sound file']);

it('records the file, the poster and every track as used by the article (I-446)', function (): void {
    $poster = pageImage($this->author, 1600, 900);
    $item = lifecycleDraft($this->author, 'Harbour news');
    docSave($item, $this->author, docWith(avVideoNode($this->video, ['posterMediaId' => $poster->id, 'tracks' => [($this->track)($this->captions)]])));

    expect([refsUses($this->video), refsUses($poster), refsUses($this->captions)])->toBe([[1, 0], [1, 0], [1, 0]]);
});

it('gives its caption as its words (I-446)', function (): void {
    expect(PlainText::of(docWith(avVideoNode($this->video, ['caption' => 'Dawn over the harbour wall']))))->toBe('Dawn over the harbour wall');
});

it('says how it changed between two versions (I-446)', function (array $before, array $after, string $line): void {
    $track = fn (string $id): array => ['mediaId' => $id, 'language' => 'en', 'kind' => 'captions', 'label' => null, 'default' => false];
    $resolve = fn (array $attrs): array => array_map(fn (mixed $value): mixed => is_array($value) ? array_map($track, $value) : $value, $attrs);

    expect(app(CardRegistry::class)->find('video')?->describeChange($resolve($before), $resolve($after)))->toBe($line);
})->with([
    'a new file' => [['mediaId' => 'A'], ['mediaId' => 'B'], 'Video — video replaced'],
    'a poster added and a caption' => [['posterMediaId' => null], ['posterMediaId' => 'P', 'caption' => 'Dawn'], 'Video — poster added and caption changed'],
    'a poster replaced, then loop on' => [['posterMediaId' => 'P', 'loop' => false], ['posterMediaId' => 'Q', 'loop' => true], 'Video — poster replaced and loop turned on'],
    'a poster removed' => [['posterMediaId' => 'P'], ['posterMediaId' => null], 'Video — poster removed'],
    'one track added' => [['tracks' => ['T1']], ['tracks' => ['T1', 'T2']], 'Video — 1 caption track added'],
    'two tracks removed' => [['tracks' => ['T1', 'T2', 'T3']], ['tracks' => ['T3']], 'Video — 2 caption tracks removed'],
    'tracks reordered' => [['tracks' => ['T1', 'T2']], ['tracks' => ['T2', 'T1']], 'Video — caption tracks changed'],
]);

it('links its style only on a page that draws it, and lets a theme replace either card\'s style (I-248)', function (): void {
    pageArticleFromDocument($this->author, 'Harbour dawn', [avVideoNode($this->video)]);
    $validator = app(ManifestValidator::class);
    $errors = fn (array $replaces): string => implode("\n", $validator->errors(pageManifest(['cards' => ['replaces' => $replaces]]), base_path('tests/Fixtures/themes/good')));

    expect(addrGet('/harbour-dawn/')->assertOk()->getContent())->toContain('data-teme-card="video"')
        ->and($errors(['audio', 'video']))->not->toContain('cards.replaces')
        ->and($errors(['podcast']))->toContain('cards.replaces names "podcast"');
});
