<?php

declare(strict_types=1);

use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentNormaliser;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\DocumentValidator;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Cards\PlainText;
use App\Domain\Cards\References;
use App\Domain\Feeds\FeedBody;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->prose = fn (string $text, array $marks = []): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, ...($marks === [] ? [] : ['marks' => $marks])]]]]];
    $this->faq = fn (array ...$items): array => ['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => array_values($items)]];
    $this->pair = fn (string $id, string $question, array $answer): array => ['id' => $id, 'question' => $question, 'answer' => $answer];
    $this->steps = fn (array $steps, array $more = []): array => ['type' => 'steps', 'attrs' => ['id' => '01K9F0A0000000000000000ST1', ...$more, 'steps' => $steps]];
    $this->step = fn (string $id, string $title, ?array $body = null, ?string $mediaId = null): array => ['id' => $id, 'title' => $title, 'body' => $body, 'mediaId' => $mediaId];
    $this->heading = fn (int $level, string $text): array => ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
});

it('draws each question as a summary that opens its answer, its heading one below the last before it', function (): void {
    $faq = ($this->faq)(($this->pair)('01K9F0A0000000000000000Q01', 'Is it <free>?', ($this->prose)('Yes, always.', [['type' => 'bold']])));

    expect(app(DocumentRenderer::class)->html(docWith($faq)))->toBe(
        '<div class="card-faq"><details class="card-faq__item"><summary><h2 class="card-faq__question">Is it &lt;free&gt;?</h2></summary><div class="card-faq__answer"><p><strong>Yes, always.</strong></p></div></details></div>',
    )
        ->and(app(DocumentRenderer::class)->html(docWith(($this->heading)(2, 'Questions'), $faq)))->toContain('<h3 class="card-faq__question">')
        ->and(app(DocumentRenderer::class)->html(docWith(($this->heading)(4, 'Deep'), $faq)))->toContain('<h5 class="card-faq__question">');
});

it('draws the steps numbered, with the summary, the time and what it needs above them', function (): void {
    $steps = ($this->steps)(
        [($this->step)('01K9F0A0000000000000000S01', 'Boil the water', ($this->prose)('Fresh water.')), ($this->step)('01K9F0A0000000000000000S02', 'Steep')],
        ['summary' => 'Brew tea.', 'totalTime' => 'PT1H5M', 'materials' => ['Tea', ' '], 'tools' => ['Teapot']],
    );

    expect(app(DocumentRenderer::class)->html(docWith(($this->heading)(2, 'How'), $steps)))->toBe(
        '<h2>How</h2><div class="card-steps"><p class="card-steps__summary">Brew tea.</p><p class="card-steps__time">Total time: <time datetime="PT1H5M">1 hour 5 minutes</time></p>'
        .'<div class="card-steps__materials"><p class="card-steps__label">You’ll need</p><ul><li>Tea</li></ul></div><div class="card-steps__tools"><p class="card-steps__label">Tools</p><ul><li>Teapot</li></ul></div>'
        .'<ol class="card-steps__list"><li class="card-steps__step" id="step-01k9f0a0000000000000000s01"><h3 class="card-steps__title">Boil the water</h3><p>Fresh water.</p></li>'
        .'<li class="card-steps__step" id="step-01k9f0a0000000000000000s02"><h3 class="card-steps__title">Steep</h3></li></ol></div>',
    );
});

it('gives both cards to feeds and the API with nothing folded away, the questions as headings and the steps as an ordered list', function (): void {
    $html = app(FeedBody::class)->html(docWith(
        ($this->faq)(($this->pair)('01K9F0A0000000000000000Q01', 'Is it free?', ($this->prose)('Yes.'))),
        ($this->steps)([($this->step)('01K9F0A0000000000000000S01', 'Boil', ($this->prose)('Hot.')), ($this->step)('01K9F0A0000000000000000S02', 'Pour', ($this->prose)('Slowly.'))], ['totalTime' => 'PT10M']),
    ));

    expect($html)->toBe('<h3>Is it free?</h3><p>Yes.</p><p>Total time: 10 minutes</p><ol><li><p><strong>Boil</strong></p><p>Hot.</p></li><li><p><strong>Pour</strong></p><p>Slowly.</p></li></ol>');
});

it('counts their words, and records what their answers link to', function (): void {
    User::factory()->create();
    $document = docWith(
        ($this->faq)(($this->pair)('01K9F0A0000000000000000Q01', 'Is it free?', ($this->prose)('See the fares', [['type' => 'link', 'attrs' => ['href' => 'teme:content/01k9f0a0000000000000000c01']]]))),
        ($this->steps)([($this->step)('01K9F0A0000000000000000S01', 'Boil', ($this->prose)('Hot water.'), '01K9F0A0000000000000000M01')], ['materials' => ['Tea']]),
    );

    expect(PlainText::of($document))->toBe('Is it free? See the fares Tea Boil Hot water.')
        ->and(References::of($document))->toEqual([CardReference::linkTo('content', '01k9f0a0000000000000000c01'), CardReference::media('01K9F0A0000000000000000M01')]);
});

it('stores a link to a page on this site inside an answer by ID, as in the body', function (): void {
    $post = headPost(User::factory()->create()->assignRole('author'), 'Ferry fares');

    $normalised = DocumentNormaliser::normalise(docWith(($this->faq)(($this->pair)('01K9F0A0000000000000000Q01', 'How much?', ($this->prose)('See the fares', [['type' => 'link', 'attrs' => ['href' => 'http://localhost/ferry-fares/']]])))));

    expect($normalised['content'][0]['attrs']['items'][0]['answer']['content'][0]['content'][0]['marks'][0]['attrs']['href'])->toBe('teme:content/'.$post->id);
});

it('refuses a card out of bounds, saying where', function (array $node, string $path): void {
    try {
        docValidate(docWith($node));
        $this->fail('The card was accepted.');
    } catch (InvalidDocumentException $exception) {
        expect($exception->path)->toBe($path);
    }
})->with([
    'no questions' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => []]], 'content.0.attrs.items'],
    'an empty question' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => [['id' => '01K9F0A0000000000000000Q01', 'question' => ' ', 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Yes.']]]]]]]]], 'content.0.attrs.items.0.question'],
    'an empty answer' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => [['id' => '01K9F0A0000000000000000Q01', 'question' => 'Why?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]]]], 'content.0.attrs.items.0.answer'],
    'a heading in an answer' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => [['id' => '01K9F0A0000000000000000Q01', 'question' => 'Why?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Big']]]]]]]]], 'content.0.attrs.items.0.answer'],
    'a card in an answer' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => [['id' => '01K9F0A0000000000000000Q01', 'question' => 'Why?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'callout', 'attrs' => ['id' => '01K9F0A0000000000000000CA1', 'text' => 'Hi']]]]]]]], 'content.0.attrs.items.0.answer'],
    'a script address in an answer' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => [['id' => '01K9F0A0000000000000000Q01', 'question' => 'Why?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]]]]]], 'content.0.attrs.items.0.answer'],
    'one id twice' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => array_fill(0, 2, ['id' => '01K9F0A0000000000000000Q01', 'question' => 'Why?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Yes.']]]]]])]], 'content.0.attrs.items.1.id'],
    'an unknown key on a question' => [['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => [['id' => '01K9F0A0000000000000000Q01', 'question' => 'Why?', 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Yes.']]]]], 'html' => '<b>x</b>']]]], 'content.0.attrs.items.0'],
    'no steps' => [['type' => 'steps', 'attrs' => ['id' => '01K9F0A0000000000000000ST1', 'steps' => []]], 'content.0.attrs.steps'],
    'a step with no title' => [['type' => 'steps', 'attrs' => ['id' => '01K9F0A0000000000000000ST1', 'steps' => [['id' => '01K9F0A0000000000000000S01', 'title' => '', 'body' => null, 'mediaId' => null]]]], 'content.0.attrs.steps.0.title'],
    'a time that isn’t a duration' => [['type' => 'steps', 'attrs' => ['id' => '01K9F0A0000000000000000ST1', 'totalTime' => '90 minutes', 'steps' => [['id' => '01K9F0A0000000000000000S01', 'title' => 'Go', 'body' => null, 'mediaId' => null]]]], 'content.0.attrs.totalTime'],
    'an empty material' => [['type' => 'steps', 'attrs' => ['id' => '01K9F0A0000000000000000ST1', 'materials' => [''], 'steps' => [['id' => '01K9F0A0000000000000000S01', 'title' => 'Go', 'body' => null, 'mediaId' => null]]]], 'content.0.attrs.materials.0'],
    'an image that isn’t an id' => [['type' => 'steps', 'attrs' => ['id' => '01K9F0A0000000000000000ST1', 'steps' => [['id' => '01K9F0A0000000000000000S01', 'title' => 'Go', 'body' => null, 'mediaId' => 'https://x.example/a.png']]]], 'content.0.attrs.steps.0.mediaId'],
]);

it('lets an autosave keep a card still being written, with no questions yet', function (): void {
    app(DocumentValidator::class)->validate(docWith(['type' => 'faq', 'attrs' => ['id' => '01K9F0A0000000000000000FQ1', 'items' => []]]), allowUnfinishedCards: true);

    expect(app(CardRegistry::class)->find('faq')?->since())->toBe('1.1.0');
});
