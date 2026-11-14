<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\KnownTargets;
use App\Domain\Import\Convert\Media\KnownMedia;
use App\Domain\Import\Phases\Content\WordPressSeo;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\StructuredData\TypeSchemas;

beforeEach(function (): void {
    $this->all = [
        BuiltInFields::SEO_TITLE, BuiltInFields::META_DESCRIPTION, BuiltInFields::CANONICAL, BuiltInFields::NOINDEX,
        BuiltInFields::SOCIAL_TITLE, BuiltInFields::SOCIAL_DESCRIPTION, BuiltInFields::SOCIAL_IMAGE,
        BuiltInFields::NOSNIPPET, BuiltInFields::MAX_SNIPPET, BuiltInFields::NOARCHIVE, BuiltInFields::PAGE_TYPE, BuiltInFields::ARTICLE_TYPE,
    ];
    $this->seo = new WordPressSeo(
        new ConversionSite('https://old.example'),
        new KnownTargets(pages: ['/ferries' => ['kind' => 'content', 'id' => '01k5z0f1ed0000000000000fer'], '/category/news' => ['kind' => 'term', 'id' => '01k5z0f1ed000000000000news']], posts: [30 => '01k5z0f1ed000000000000post']),
        new KnownMedia([41 => '01K5Z0F1ED00000000000PHOTO'], ['2024/05/harbour.jpg' => '01K5Z0F1ED0000000000HARBOR']),
    );
});

/**
 * The SEO plugin's values as the companion sends them, all empty but $values.
 *
 * @param  array<string, mixed>  $values
 *
 * @return array<string, mixed>
 */
function wpsValues(array $values = []): array
{
    return [
        'title' => null, 'description' => null, 'canonical' => null, 'noindex' => null, 'nofollow' => false,
        'social_title' => null, 'social_description' => null, 'social_image_id' => null, 'social_image_url' => null,
        'primary_terms' => [], 'had_variables' => [], ...$values,
    ];
}

/**
 * The codes of a reading's messages.
 *
 * @param  array{messages: list<ImportMessage>}  $read
 *
 * @return list<string>
 */
function wpsCodes(array $read): array
{
    return array_map(fn (ImportMessage $message): string => $message->code, $read['messages']);
}

it('keeps the titles and descriptions as plain words, cut to Teme’s lengths, saying which', function (): void {
    $read = $this->seo->read(wpsValues([
        'title' => 'Harbour news &amp; tides', 'description' => str_repeat('é', 400),
        'social_title' => '  Harbour   news ', 'social_description' => 'Boats at dawn.',
    ]), 12, 'harbour-news', $this->all, 'category');

    expect($read['fields'])->toBe([
        BuiltInFields::SEO_TITLE => 'Harbour news & tides',
        BuiltInFields::META_DESCRIPTION => str_repeat('é', 320),
        BuiltInFields::SOCIAL_TITLE => 'Harbour news',
        BuiltInFields::SOCIAL_DESCRIPTION => 'Boats at dawn.',
    ])->and($read['shortened'])->toBe(['meta description'])
        ->and(wpsCodes($read))->toBe([]);
});

it('keeps what the plugin’s variables gave as words, saying so once', function (): void {
    $read = $this->seo->read(wpsValues(['title' => 'Harbour news – Harbour Notes', 'description' => 'Tides', 'had_variables' => ['title', 'description']]), 12, 'harbour-news', $this->all, 'category');

    expect($read['fields'][BuiltInFields::SEO_TITLE])->toBe('Harbour news – Harbour Notes')
        ->and(wpsCodes($read))->toBe(['seo.variables'])
        ->and($read['messages'][0]->message)->toBe('Its SEO title and meta description used its SEO plugin’s variables; Teme kept the words they gave on WordPress, which won’t follow later changes to the title.');
});

it('hides an item from search engines only when the plugin asked, and says what of nofollow it leaves', function (): void {
    $hidden = $this->seo->read(wpsValues(['noindex' => true, 'nofollow' => true]), 12, 'harbour-news', $this->all, 'category');
    $indexed = $this->seo->read(wpsValues(['noindex' => false]), 12, 'harbour-news', $this->all, 'category');

    expect($hidden['fields'])->toBe([BuiltInFields::NOINDEX => true])
        ->and(wpsCodes($hidden))->toBe(['seo.nofollow-not-kept'])
        ->and($indexed['fields'])->toBe([]);
});

it('turns a canonical on the old site into the item it leads to, or the item itself, and never keeps the old address', function (string $canonical, ?array $field, ?string $pending, bool $dropped): void {
    $read = $this->seo->read(wpsValues(['canonical' => $canonical]), 12, 'harbour-news', $this->all, 'category');

    expect($read['fields'][BuiltInFields::CANONICAL] ?? null)->toBe($field)
        ->and($read['pendingCanonical'])->toBe($pending)
        ->and(in_array('seo.canonical-dropped', wpsCodes($read), true))->toBe($dropped)
        ->and(json_encode($read['fields']))->not->toContain('old.example');
})->with([
    'its own address' => ['https://www.old.example/Harbour-News/', null, null, false],
    'its own number' => ['https://old.example/?p=12', null, null, false],
    'another item by address' => ['https://old.example/ferries/', ['item' => '01k5z0f1ed0000000000000fer'], null, false],
    'another item by number' => ['/?p=30', ['item' => '01k5z0f1ed000000000000post'], null, false],
    'an item not here yet' => ['https://old.example/later-story/', null, '/later-story/', false],
    'another site' => ['https://news.example/harbour/', ['address' => 'https://news.example/harbour/'], null, false],
    'a category page' => ['https://old.example/category/news/', null, '/category/news/', false],
    'a file on the old site' => ['https://old.example/wp-content/uploads/2024/05/report.pdf', null, null, true],
    'a script' => ['javascript:alert(1)', null, null, true],
]);

it('keeps the social image by its attachment or its file, says a missing one, and leaves another site’s', function (): void {
    $byId = $this->seo->read(wpsValues(['social_image_id' => 41, 'social_image_url' => 'https://old.example/wp-content/uploads/2024/05/other.jpg']), 12, null, $this->all, 'category');
    $byFile = $this->seo->read(wpsValues(['social_image_url' => 'https://old.example/wp-content/uploads/2024/05/harbour-1200x630.jpg']), 12, null, $this->all, 'category');
    $missing = $this->seo->read(wpsValues(['social_image_id' => 99, 'social_image_url' => 'https://old.example/wp-content/uploads/2024/05/gone.jpg']), 12, null, $this->all, 'category');
    $outside = $this->seo->read(wpsValues(['social_image_url' => 'https://cdn.partner.example/cards/harbour.jpg']), 12, null, $this->all, 'category');

    expect($byId['fields'][BuiltInFields::SOCIAL_IMAGE])->toBe(['id' => '01K5Z0F1ED00000000000PHOTO', 'alt' => null, 'decorative' => false])
        ->and($byFile['fields'][BuiltInFields::SOCIAL_IMAGE])->toBe(['id' => '01K5Z0F1ED0000000000HARBOR', 'alt' => null, 'decorative' => false])
        ->and($missing['fields'])->not->toHaveKey(BuiltInFields::SOCIAL_IMAGE)
        ->and($missing['missingImage'])->toBeTrue()
        ->and(wpsCodes($missing))->toBe(['seo.social-image-missing'])
        ->and($outside['fields'])->not->toHaveKey(BuiltInFields::SOCIAL_IMAGE)
        ->and($outside['missingImage'])->toBeFalse()
        ->and($outside['messages'][0]->message)->toContain('(cdn.partner.example)');
});

it('sets the three snippet directives the plugin sent (I-714)', function (): void {
    $read = $this->seo->read(wpsValues(['nosnippet' => true, 'noarchive' => true, 'max_snippet' => 160]), 12, null, $this->all, 'category');
    $unlimited = $this->seo->read(wpsValues(['nosnippet' => false, 'max_snippet' => -1]), 12, null, $this->all, 'category');

    expect($read['fields'])->toBe([BuiltInFields::NOSNIPPET => true, BuiltInFields::NOARCHIVE => true, BuiltInFields::MAX_SNIPPET => 160])
        ->and($unlimited['fields'])->toBe([]);
});

it('keeps Yoast’s page and article types Teme has, and says which it hasn’t (I-709)', function (): void {
    $kept = $this->seo->read(wpsValues(['page_type' => 'AboutPage', 'article_type' => 'None']), 12, null, $this->all, 'category');
    $left = $this->seo->read(wpsValues(['page_type' => 'FAQPage', 'article_type' => 'TechArticle']), 12, null, $this->all, 'category');

    expect($kept['fields'])->toBe([BuiltInFields::PAGE_TYPE => 'AboutPage', BuiltInFields::ARTICLE_TYPE => TypeSchemas::NONE])
        ->and($left['fields'])->toBe([])
        ->and(wpsCodes($left))->toBe(['seo.type-not-kept', 'seo.type-not-kept']);
});

it('gives the primary term the plugin chose in the type’s category taxonomy, and sets nothing for a type without SEO fields', function (): void {
    $read = $this->seo->read(wpsValues(['primary_terms' => ['category' => 7, 'product_cat' => 9], 'title' => 'Harbour']), 12, null, $this->all, 'category');
    $none = $this->seo->read(wpsValues(['primary_terms' => ['category' => 7], 'title' => 'Harbour']), 12, null, [], null);

    expect($read['primaryTermId'])->toBe(7)
        ->and($none)->toBe(['fields' => [], 'primaryTermId' => null, 'pendingCanonical' => null, 'missingImage' => false, 'shortened' => [], 'messages' => []]);
});
