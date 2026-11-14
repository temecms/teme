<?php

declare(strict_types=1);

use App\Domain\Search\PostgresSiteSearch;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Search\SearchHits;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

it('finds a French item with French stemming at the French search', function (): void {
    LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);

    $hits = app(PostgresSiteSearch::class)->search(new SearchRequest('manger', 1, 10, null, 'fr'));

    expect($hits->total)->toBe(1)
        ->and((string) addrGet('/fr/?s=manger')->assertOk()->assertHeader('Content-Language', 'fr')->getContent())->toContain('Ils mangeaient');
});

it('never finds a French item from the default language\'s search, nor an English one from the French', function (): void {
    expect((string) addrGet('/?s=bonjour')->assertOk()->getContent())->not->toContain('href="/fr/bonjour/"')
        ->and((string) addrGet('/fr/?s=hello')->assertOk()->getContent())->not->toContain('href="/hello/"')
        ->and((string) addrGet('/?s=hola')->assertOk()->getContent())->not->toContain('href="/es/hola/"');
});

it('counts and ranks only the language searched', function (): void {
    $search = app(PostgresSiteSearch::class);
    $english = $search->search(new SearchRequest('few words', 1, 10));
    $french = $search->search(new SearchRequest('few words', 1, 10, null, 'fr'));

    // Hello, Alone and About in English; Bonjour alone in French, its page a draft.
    expect($english->total)->toBe(3)
        ->and($french->total)->toBe(1)
        ->and(array_map(fn ($hit): string => $hit->contentId, $french->hits))->toBe([$this->site->postFr]);
});

it('tells a search binding the language searched', function (): void {
    $asked = new ArrayObject;
    app()->bind(SiteSearch::class, fn () => new class($asked) implements SiteSearch
    {
        public function __construct(private ArrayObject $asked) {}

        public function search(SearchRequest $request): SearchHits
        {
            $this->asked[] = $request->language;

            return new SearchHits([], 0, false);
        }
    });

    addrGet('/fr/?s=bonjour')->assertOk();
    addrGet('/?s=hello')->assertOk();

    expect($asked->getArrayCopy())->toBe(['fr', null]);
});
