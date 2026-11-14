<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\FieldView;
use App\Domain\Site\ViewModels\ImageRef;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const SFL_RELEASE = '01K9F1E1D0000000000000RD01';
const SFL_RUNTIME = '01K9F1E1D0000000000000RT02';
const SFL_RATING = '01K9F1E1D0000000000000RA03';
const SFL_CINEMAS = '01K9F1E1D0000000000000CN04';
const SFL_TRAILER = '01K9F1E1D0000000000000TR05';
const SFL_POSTER = '01K9F1E1D0000000000000PS06';
const SFL_TAGLINE = '01K9F1E1D0000000000000TG07';
const SFL_SEQUEL = '01K9F1E1D0000000000000SQ08';
const SFL_PREQUEL = '01K9F1E1D0000000000000PQ09';
const SFL_NOTE = '01K9F1E1D0000000000000NT10';
const SFL_PREMIERE = '01K9F1E1D0000000000000PM11';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = ctAdmin();
    lscrTimezone('Africa/Lagos');
    siteSetLanguage($this->admin, 'en-GB');
    StoredFieldRows::movieType();
});

/**
 * The standard fields a movie shows after its article, in order: a date-only
 * release, a runtime, a rating, a Yes or no, a trailer, a poster and a tagline.
 */
function sflStandardFields(): void
{
    StoredFieldRows::store(StoredFieldRows::entry(SFL_RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true]]), 1);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_RUNTIME, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number']), 2);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_RATING, 'rating', ['label' => 'Rating', 'fieldType' => 'select', 'settings' => ['choices' => [['value' => 'pg', 'label' => 'PG-13'], ['value' => 'r', 'label' => 'R']]]]), 3);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_CINEMAS, 'in_cinemas', ['label' => 'In cinemas', 'fieldType' => 'boolean']), 4);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_TRAILER, 'trailer_url', ['label' => 'Trailer URL', 'fieldType' => 'url']), 5);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_POSTER, 'poster', ['label' => 'Poster', 'fieldType' => 'media']), 6);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_TAGLINE, 'tagline', ['label' => 'Tagline']), 7);
}

/**
 * A movie holding these field values, published unless told not to.
 *
 * @param  array<string, mixed>  $fields
 */
function sflMovie(string $title, array $fields, bool $publish = true): Content
{
    $movie = lifecycleDraft(test()->admin, $title, 'movie')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The body.']]]]], $fields, $movie->working_revision_id, (string) test()->admin->id));

    if ($publish) {
        listsPublish($movie, test()->admin);
    }

    return $movie->refresh();
}

/**
 * The movie's public page.
 */
function sflPage(Content $movie): string
{
    return addrGet((string) Addresses::relativeUrl($movie))->assertOk()->getContent();
}

/**
 * Each listed field's label and value text, in order.
 *
 * @return list<array{string, string}>
 */
function sflListed(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $listed = [];

    foreach ((new DOMXPath($document))->query('//dl[@class="field-list"]/div') ?: [] as $row) {
        $listed[] = [
            trim((string) preg_replace('/\s+/', ' ', (string) $row->getElementsByTagName('dt')->item(0)?->textContent)),
            trim((string) preg_replace('/\s+/', ' ', (string) $row->getElementsByTagName('dd')->item(0)?->textContent)),
        ];
    }

    return $listed;
}

/**
 * An uploaded image's media ID.
 */
function sflPoster(): string
{
    return mediaUpload(test()->admin, mediaJpeg(), 'Poster.jpg')->id;
}

it('lists a movie’s shown fields after the article, label and value, in order, skipping empty ones', function (): void {
    sflStandardFields();
    $poster = sflPoster();
    $movie = sflMovie('Dune', [
        SFL_RELEASE => '2024-03-01', SFL_RUNTIME => 166, SFL_RATING => 'pg', SFL_CINEMAS => false,
        SFL_TRAILER => 'https://example.com/t', SFL_POSTER => ['id' => $poster, 'alt' => 'The poster', 'decorative' => false], SFL_TAGLINE => '',
    ]);

    $html = sflPage($movie);

    expect($html)->toContain('<dl class="field-list">')
        ->and(sflListed($html))->toBe([['Release date', '1 March 2024'], ['Runtime', '166'], ['Rating', 'PG-13'], ['Trailer URL', 'https://example.com/t'], ['Poster', '']])
        ->and($html)->not->toContain('In cinemas')->not->toContain('Tagline')
        ->and($html)->toContain('<a href="https://example.com/t">')
        ->and(strpos($html, 'class="prose"'))->toBeLessThan((int) strpos($html, 'class="field-list"'))
        ->and(strpos($html, 'class="field-list"'))->toBeLessThan((int) strpos($html, '</article>'));
});

it('gives each listed field as a FieldView with its plain value and no framework type', function (): void {
    sflStandardFields();
    $poster = sflPoster();
    $movie = sflMovie('Dune', [
        SFL_RELEASE => '2024-03-01', SFL_RUNTIME => 166, SFL_RATING => 'pg', SFL_CINEMAS => false,
        SFL_TRAILER => 'https://example.com/t', SFL_POSTER => ['id' => $poster, 'alt' => 'The poster', 'decorative' => false], SFL_TAGLINE => '',
    ]);

    $after = app(ArticleViews::class)->published($movie)->shownFields('after');

    expect($after)->toHaveCount(5)
        ->and($after[0])->toBeInstanceOf(FieldView::class)
        ->and($after[0]->type)->toBe('date')
        ->and(get_class($after[0]->value))->toBe(DateTimeImmutable::class)
        ->and($after[0]->value->format('Y-m-d H:i e'))->toBe('2024-03-01 00:00 Africa/Lagos')
        ->and([$after[1]->value, $after[1]->text, $after[1]->show, $after[1]->empty])->toBe([166, '166', 'after', false])
        ->and([$after[2]->value, $after[2]->text])->toBe(['pg', 'PG-13'])
        ->and((string) $after[3])->toBe('https://example.com/t')
        ->and($after[3]->url)->toBe('https://example.com/t')
        ->and($after[4]->image)->toBeInstanceOf(ImageRef::class)
        ->and($after[4]->value)->toBe($poster)
        ->and(array_map(fn (FieldView $field): string => $field->handle, $after))->not->toContain('in_cinemas', 'tagline');
});

it('holds no object from the framework in any field view', function (): void {
    sflStandardFields();
    StoredFieldRows::store(StoredFieldRows::entry(SFL_PREMIERE, 'premiere', ['label' => 'Premiere', 'fieldType' => 'datetime']), 8);
    $movie = sflMovie('Dune', [SFL_RELEASE => '2024-03-01', SFL_PREMIERE => '2016-09-01T18:30:00+00:00', SFL_POSTER => ['id' => sflPoster(), 'alt' => null, 'decorative' => false]]);

    foreach (app(ArticleViews::class)->published($movie)->fieldViews as $view) {
        foreach (get_object_vars($view) as $property) {
            if (is_object($property)) {
                expect(str_starts_with($property::class, 'Illuminate\\') || str_starts_with($property::class, 'Carbon\\'))->toBeFalse()
                    ->and($property::class)->toBeIn([DateTimeImmutable::class, ImageRef::class]);
            }
        }
    }
});

it('puts a field shown before the article above the body, and leaves out one not shown', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_RUNTIME, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number', 'show' => 'before']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_RATING, 'rating', ['label' => 'Rating', 'show' => 'hidden']), 2);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_TAGLINE, 'tagline', ['label' => 'Tagline']), 3);
    $html = sflPage(sflMovie('Dune', [SFL_RUNTIME => 166, SFL_RATING => 'Secret', SFL_TAGLINE => 'Fear is the mind-killer']));

    expect(sflListed($html))->toBe([['Runtime', '166'], ['Tagline', 'Fear is the mind-killer']])
        ->and(strpos($html, 'Runtime'))->toBeLessThan((int) strpos($html, 'class="prose"'))
        ->and(strpos($html, 'class="prose"'))->toBeLessThan((int) strpos($html, 'Fear is the mind-killer'))
        ->and($html)->not->toContain('Secret');
});

it('draws no list when every shown field is empty', function (): void {
    sflStandardFields();

    expect(sflPage(sflMovie('Dune', [SFL_TAGLINE => ''])))->not->toContain('field-list');
});

it('links an item field to its target’s address now, and leaves it out when that item isn’t published', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_SEQUEL, 'sequel', ['label' => 'Sequel', 'fieldType' => 'entry']), 1);
    $arrival = sflMovie('Arrival', []);
    $dune = sflMovie('Dune', [SFL_SEQUEL => $arrival->id]);

    $html = sflPage($dune);

    expect($html)->toContain('<a href="'.Addresses::relativeUrl($arrival).'">Arrival</a>')
        ->and(sflListed($html))->toBe([['Sequel', 'Arrival']]);

    app(RenderRecord::class)->start(0);
    app(ArticleViews::class)->published($dune->refresh());
    expect(app(RenderRecord::class)->tags())->toContain(PageTags::content($arrival->id));

    $address = (string) Addresses::relativeUrl($arrival);
    app(UnpublishContent::class)->execute(new ContentActionInput($arrival->id, (string) $this->admin->id));

    expect(sflPage($dune))->not->toContain('field-list');
    sflExpectEntryHidden($dune, $arrival, $address);
});

/**
 * The movie's sequel field as the page model holds it once its target is
 * out of sight: empty, no value, no title or address in the page.
 */
function sflExpectEntryHidden(Content $movie, Content $target, string $address): void
{
    $sequel = app(ArticleViews::class)->published($movie->refresh())->fieldViews['sequel'];
    $html = sflPage($movie);

    expect([$sequel->empty, $sequel->value, $sequel->text, $sequel->url])->toBe([true, null, '', null])
        ->and($address)->not->toBe('')
        ->and($html)->not->toContain($address)->not->toContain('Arrival')->not->toContain($target->id);
}

it('leaves out a linked item that is private, with no value, title or address', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_SEQUEL, 'sequel', ['label' => 'Sequel', 'fieldType' => 'entry']), 1);
    $arrival = sflMovie('Arrival', []);
    $dune = sflMovie('Dune', [SFL_SEQUEL => $arrival->id]);
    $address = (string) Addresses::relativeUrl($arrival);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($arrival->id, 'private', null, (string) $this->admin->id));

    expect(sflPage($dune))->not->toContain('field-list');
    sflExpectEntryHidden($dune, $arrival, $address);
});

it('leaves out a linked item that is in the trash, with no value, title or address', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_SEQUEL, 'sequel', ['label' => 'Sequel', 'fieldType' => 'entry']), 1);
    $arrival = sflMovie('Arrival', []);
    $dune = sflMovie('Dune', [SFL_SEQUEL => $arrival->id]);
    $address = (string) Addresses::relativeUrl($arrival);
    pageTrash($arrival, $this->admin);

    expect(sflPage($dune))->not->toContain('field-list');
    sflExpectEntryHidden($dune, $arrival, $address);
});

it('reads two item fields in one statement', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_SEQUEL, 'sequel', ['label' => 'Sequel', 'fieldType' => 'entry']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(SFL_PREQUEL, 'prequel', ['label' => 'Prequel', 'fieldType' => 'entry']), 2);
    $one = sflMovie('Arrival', []);
    $two = sflMovie('Sicario', []);
    $movie = sflMovie('Dune', [SFL_SEQUEL => $one->id, SFL_PREQUEL => $two->id]);

    DB::enableQueryLog();
    $view = app(ArticleViews::class)->published($movie);
    $titleReads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], '"content_revisions"."title"') && in_array($one->id, $query['bindings'], true));

    expect($titleReads)->toHaveCount(1)
        ->and(array_map(fn (FieldView $field): string => $field->text, array_values($view->fieldViews)))->toBe(['Arrival', 'Sicario']);
});

it('draws a long text’s lines and escapes what it holds', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_NOTE, 'note', ['label' => 'Note', 'fieldType' => 'textarea']), 1);

    expect(sflPage(sflMovie('Dune', [SFL_NOTE => "A <b>\nB"])))->toContain('A &lt;b&gt;<br>B');
});

it('shows a ticked Yes or no as Yes', function (): void {
    sflStandardFields();

    expect(sflListed(sflPage(sflMovie('Dune', [SFL_CINEMAS => true]))))->toBe([['In cinemas', 'Yes']]);
});

it('leaves out a media field whose item is in the trash', function (): void {
    sflStandardFields();
    $poster = sflPoster();
    $movie = sflMovie('Dune', [SFL_RUNTIME => 166, SFL_POSTER => ['id' => $poster, 'alt' => null, 'decorative' => false]]);
    app(TrashMedia::class)->execute(new MediaActionInput($poster, (string) $this->admin->id));

    $poster = app(ArticleViews::class)->published($movie->refresh())->fieldViews['poster'];

    expect(sflListed(sflPage($movie)))->toBe([['Runtime', '166']])
        ->and([$poster->empty, $poster->value, $poster->image])->toBe([true, null, null]);
});

it('draws no link for a web address that isn’t one', function (): void {
    sflStandardFields();
    $movie = sflMovie('Dune', [SFL_RUNTIME => 1]);
    DB::table('content_revisions')->where('id', $movie->published_revision_id)->update(['fields' => json_encode([SFL_RUNTIME => 1, SFL_TRAILER => 'javascript:alert(1)'])]);

    $html = sflPage($movie->refresh());

    expect($html)->not->toContain('javascript:')->and(sflListed($html))->toBe([['Runtime', '1']]);
});

it('lists fields on a page too', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_TAGLINE, 'tagline', ['label' => 'Tagline', 'type' => 'page']), 1);
    $page = lifecycleDraft($this->admin, 'About us', 'page')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($page->id, 'About us', ['type' => 'doc', 'content' => []], [SFL_TAGLINE => 'Since 2004'], $page->working_revision_id, (string) $this->admin->id));
    listsPublish($page, $this->admin);

    expect(sflListed(sflPage($page->refresh())))->toBe([['Tagline', 'Since 2004']]);
});

it('draws a date and time on the site’s clock', function (): void {
    StoredFieldRows::store(StoredFieldRows::entry(SFL_PREMIERE, 'premiere', ['label' => 'Premiere', 'fieldType' => 'datetime']), 1);

    expect(sflListed(sflPage(sflMovie('Dune', [SFL_PREMIERE => '2016-09-01T18:30:00+00:00']))))->toBe([['Premiere', '1 September 2016 19:30']]);
});
