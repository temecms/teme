<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Cards\Imported\MarkupCleaner;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\PlainText;
use App\Domain\Cards\References;
use App\Domain\Cards\Types\ImportedContentCard;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Feeds\FeedBody;
use App\Domain\Feeds\FeedUrls;
use App\Domain\Media\ImageMarkup;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $this->render = fn (array ...$nodes): string => app(DocumentRenderer::class)->html(docWith(...$nodes));
    $this->refusal = function (array $document, bool $unfinished = false): string {
        try {
            app(DocumentValidator::class)->validate($document, allowUnfinishedCards: $unfinished);
        } catch (InvalidDocumentException $refusal) {
            return ($refusal->path === '' ? '' : "{$refusal->path}: ").$refusal->errors()['document'][0];
        }

        return 'accepted';
    };
});

it('renders the fixture to its expected HTML (I-329)', function (): void {
    expect(app(DocumentRenderer::class)->html(icFixture()))
        ->toBe(trim((string) file_get_contents(base_path('tests/Fixtures/Documents/imported.html'))));
});

it('accepts the fixture as the importer writes it, and an unfinished card only in an autosave (I-329)', function (): void {
    $unfinished = docWith(icNode([], ['markup' => null, 'sourceKind' => null]));

    expect(($this->refusal)(icFixture()))->toBe('accepted')
        ->and(($this->refusal)($unfinished, unfinished: true))->toBe('accepted')
        ->and(($this->refusal)($unfinished))->toBe('content.0.attrs.markup: The markup field is required.');
});

it('checks the list in an autosave too, so no save stores what the list refuses (I-329)', function (): void {
    $smuggled = docWith(icNode([icEl('p', [icText('Tide')], ['onclick' => 'steal()'])]));

    expect(($this->refusal)($smuggled, unfinished: true))->toBe('content.0.attrs.markup: 0: [p] can’t carry [onclick].')
        ->and(($this->refusal)($smuggled))->toBe('content.0.attrs.markup: 0: [p] can’t carry [onclick].')
        ->and(fn () => docSave(lifecycleDraft($this->author, 'Tides'), $this->author, $smuggled))->toThrow(InvalidDocumentException::class);
});

it('never draws an element off the list, even from a tree that was never checked (I-329)', function (): void {
    $html = ($this->render)(icNode([
        icEl('script', [icText('steal()')]),
        icEl('p', [icText('Kept'), icEl('img', [], ['mediaId' => '01K6A0D1000000000000000ZZ9', 'onerror' => 'steal()'])], ['onclick' => 'steal()', 'style' => 'color:red']),
        icEl('a', [icText('Go')], ['href' => 'javascript:steal()']),
        icText('<b>bold</b>'),
        icEl('iframe', [icText('frame')]),
    ]));

    expect($html)->toBe('<div class="card-imported"><p>Kept</p>Go&lt;b&gt;bold&lt;/b&gt;</div>');
});

it('draws a link to an article at its address now, and only its words once the article has gone (rule 9)', function (): void {
    $article = pageArticleFromDocument($this->author, 'Harbour news', [docText('News.')]);
    $card = icNode([icEl('p', [icText('See '), icEl('a', [icText('the news')], ['href' => "teme:content/{$article->id}"])])]);
    $address = app(LinkResolver::class)->url($article->id);

    expect($address)->not->toBeNull()
        ->and(($this->render)($card))->toBe('<div class="card-imported"><p>See <a href="'.e((string) $address).'">the news</a></p></div>');

    app(TrashContent::class)->execute(new ContentActionInput($article->id, (string) $this->editor->id));

    expect(($this->render)($card))->toBe('<div class="card-imported"><p>See the news</p></div>');
});

it('draws a library picture as every picture in an article\'s body is, and nothing once it\'s in the trash (I-329)', function (): void {
    $image = pageImage($this->author, 800, 600);
    $card = icNode([icEl('p', [icEl('img', [], ['mediaId' => $image->id, 'alt' => 'The quay'])])]);
    $expected = app(ImageMarkup::class)->item(app(CardMedia::class)->forIds([$image->id])[$image->id], 'content', false, 'The quay');

    expect($expected)->toContain('<img')
        ->and(($this->render)($card))->toBe('<div class="card-imported"><p>'.$expected.'</p></div>');

    app(TrashMedia::class)->execute(new MediaActionInput($image->id, (string) $this->editor->id));
    // CardMedia remembers what a request has read; a new request reads again.
    app()->forgetScopedInstances();

    expect(($this->render)($card))->toBe('<div class="card-imported"><p></p></div>');
});

it('gives its words to search and the word count, as the fidelity check counts them (I-329)', function (): void {
    expect(PlainText::of(icFixture()))->toBe('High water, this week Day Time Monday 6:12am No sailings on Tuesday & Wednesday Ebb The tide going out. See the tide tables. H2O, always cold. Mind the steps.')
        ->and(PlainText::words(icFixture()))->toBe(29);
});

it('records the pictures it shows and the articles it links to, so the library and backlinks know (I-329)', function (): void {
    $image = pageImage($this->author, 800, 600);
    $article = pageArticleFromDocument($this->author, 'Harbour news', [docText('News.')]);
    $document = docWith(icNode([icEl('p', [icEl('img', [], ['mediaId' => $image->id]), icEl('a', [icText('news')], ['href' => "teme:content/{$article->id}"])])]));
    docSave(lifecycleDraft($this->author, 'Tide tables'), $this->author, $document);

    expect(refsUses($image))->toBe([1, 0])
        ->and(array_map(fn ($reference): string => "{$reference->kind}:{$reference->id}", References::of($document)))->toBe(["media:{$image->id}", "content:{$article->id}"]);
});

it('says how it changed between two versions (I-329)', function (string $case): void {
    [$before, $after, $line] = match ($case) {
        'kept' => [['kept' => false], ['kept' => true], 'Imported content — kept'],
        'no longer kept' => [['kept' => true], ['kept' => false], 'Imported content — no longer kept'],
        default => [['markup' => [['text' => 'Old']]], ['markup' => [['text' => 'New']]], 'Imported content — content changed'],
    };

    expect(app(CardRegistry::class)->find('imported')?->describeChange($before, $after))->toBe($line);
})->with(['kept', 'no longer kept', 'a catch-up\'s new markup']);

it('links its style only on a page that draws it (I-248)', function (): void {
    pageArticleFromDocument($this->author, 'Tide tables', [icFixture()['content'][0]]);
    pageArticleFromDocument($this->author, 'Quiet day', [docText('Nothing imported.')]);

    expect(addrGet('/tide-tables/')->assertOk()->getContent())->toContain('data-teme-card="imported"')
        ->and(addrGet('/quiet-day/')->assertOk()->getContent())->not->toContain('data-teme-card="imported"');
});

it('builds the node the importer writes: a new node ID, where it came from, and not yet kept (I-329, I-1704)', function (): void {
    $node = ImportedContentCard::node(app(MarkupCleaner::class)->clean('<p>Ferry at <b>07:30</b></p>'), 'shortcode', 'table');

    expect($node['type'])->toBe('imported')
        ->and($node['attrs'])->toMatchArray(['markup' => [icEl('p', [icText('Ferry at '), icEl('strong', [icText('07:30')])])], 'sourceKind' => 'shortcode', 'sourceName' => 'table', 'kept' => false])
        ->and($node['attrs']['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and(($this->refusal)(docWith($node)))->toBe('accepted');
});

it('is never offered by + Insert or the slash menu, while every other card is (I-1715)', function (): void {
    $cards = collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->keyBy('name');

    expect($cards['imported']['inMenus'])->toBeFalse()
        ->and($cards->except(['imported', 'plugin-card'])->pluck('inMenus')->unique()->values()->all())->toBe([true])
        ->and(app(CardRegistry::class)->find('imported')?->menu()->inMenus)->toBeFalse();
});

it('writes a plain version for feeds: the same elements with no wrapper, links and pictures at absolute addresses (I-861)', function (): void {
    $article = pageArticleFromDocument($this->author, 'Harbour news', [docText('News.')]);
    $image = pageImage($this->author, 800, 600);
    $html = app(FeedBody::class)->html(docWith(icNode([
        icEl('p', [icText('See '), icEl('a', [icText('the news')], ['href' => "teme:content/{$article->id}"])]),
        icEl('p', [icEl('img', [], ['mediaId' => $image->id, 'alt' => 'The quay'])]),
    ])));
    $address = app(FeedUrls::class)->absolute((string) app(LinkResolver::class)->url($article->id));

    expect($html)->toStartWith('<p>See <a href="'.e($address).'">the news</a></p><p><img ')
        ->toContain('alt="The quay"')
        ->not->toContain('card-imported')
        ->not->toContain('src="/');
});
