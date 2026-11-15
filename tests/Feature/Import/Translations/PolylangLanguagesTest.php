<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Import\Translations\PolylangLanguages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\PolylangSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->site = PolylangSite::serve();
    $this->reader = fn (): SourceReader => app(CompanionClients::class)->for(new ImportSource([
        'address' => FakeCompanion::SITE, 'api_root' => FakeCompanion::SITE.'/wp-json/',
        'user_name' => FakeCompanion::USER, 'credential' => FakeCompanion::PASSWORD,
    ]));
});

it('reads the old site\'s languages from the companion, in Polylang\'s order, with Polylang\'s default', function (): void {
    $languages = PolylangLanguages::fromAnswer(($this->reader)()->whole('languages'));

    expect($languages->active)->toBeTrue()
        ->and($languages->default)->toBe('en')
        ->and(array_column($languages->languages, 'slug'))->toBe(['en', 'es', 'pt', 'pe', 'it', 'hi', 'ja'])
        ->and($languages->mode())->toBe(PolylangLanguages::DIRECTORY)
        ->and($languages->translatesType('page'))->toBeTrue()
        ->and($languages->translatesTaxonomy('category'))->toBeTrue()
        ->and($languages->menus)->toBe([PolylangSite::THEME => ['primary' => ['en' => 3, 'es' => 4]]])
        ->and($this->site->companion->requestsTo('languages')[0]['signedIn'])->toBeTrue();
});

it('gives each language the Teme language its locale becomes, its prefix staying Polylang\'s slug', function (): void {
    $languages = PolylangLanguages::fromAnswer(PolylangSite::languages());

    expect(array_combine(array_column($languages->languages, 'slug'), array_column($languages->languages, 'language')))->toBe(array_flip(PolylangSite::SLUGS))
        ->and(PolylangLanguages::tagFor('es_ES'))->toBe('es')
        ->and(PolylangLanguages::tagFor('pt_BR'))->toBe('pt-BR')
        ->and(PolylangLanguages::tagFor('pt_PT'))->toBe('pt')
        ->and(PolylangLanguages::tagFor('it_IT'))->toBe('it')
        ->and(PolylangLanguages::tagFor('hi_IN'))->toBe('hi')
        ->and(PolylangLanguages::tagFor('ja'))->toBe('ja')
        ->and(PolylangLanguages::tagFor('en_US'))->toBe('en')
        ->and(PolylangLanguages::tagFor('en_GB'))->toBe('en-GB')
        ->and(PolylangLanguages::tagFor('tlh'))->toBeNull();
});

it('reads a site without Polylang as one language, as the companion answers it', function (): void {
    $this->site->with('languages', ['api' => 1, 'active' => false]);

    expect(PolylangLanguages::fromAnswer(($this->reader)()->whole('languages'))->active)->toBeFalse();
});

it('reads a site whose companion has no languages route as one without Polylang, and keeps the answer', function (): void {
    $this->site->companion->answer('languages', 404, ['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]]);
    $source = importSource();

    $languages = app(ImportedLanguages::class)->refresh($source->id, ($this->reader)());

    expect($languages->active)->toBeFalse()
        ->and(app(ImportedLanguages::class)->for($source->id)->active)->toBeFalse()
        ->and($this->site->companion->requestsTo('languages'))->toHaveCount(1);
});

it('asks the old site once while its answer is kept', function (): void {
    $source = importSource();

    app(ImportedLanguages::class)->for($source->id);
    $languages = app(ImportedLanguages::class)->for($source->id);

    expect($languages->default)->toBe('en')
        ->and(array_column($languages->languages, 'language'))->toBe(array_keys(PolylangSite::SLUGS))
        ->and($this->site->companion->requestsTo('languages'))->toHaveCount(1);
});
