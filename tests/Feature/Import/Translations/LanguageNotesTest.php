<?php

declare(strict_types=1);

use App\Domain\Import\Planning\ImportAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Import\PolylangSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->site = PolylangSite::serve();
    $this->source = importSource();
});

/**
 * The keys of what the analysis says won't come across about languages.
 *
 * @return list<string>
 */
function lnKeys(string $sourceId): array
{
    $stays = app(ImportAnalysis::class)->read($sourceId)->toArray()['stays'];

    return array_values(array_filter(array_column($stays, 'key'), fn (string $key): bool => str_starts_with($key, 'languages.')));
}

it('says nothing of a Polylang site whose languages are in its addresses\' first directory', function (): void {
    expect(lnKeys($this->source->id))->toBe([]);
});

it('says the import stops on a site whose Polylang sets the language from the content', function (): void {
    $this->site->with('languages', PolylangSite::languages(['options' => ['force_lang' => 0]]));

    expect(lnKeys($this->source->id))->toBe(['languages.from-content']);
});

it('says each language gets a first part of the address instead of its subdomain or domain', function (int $mode): void {
    $this->site->with('languages', PolylangSite::languages(['options' => ['force_lang' => $mode]]));

    expect(lnKeys($this->source->id))->toBe(['languages.per-host']);
})->with(['subdomains' => 2, 'domains' => 3]);

it('says Teme can\'t import TranslatePress\'s or MultilingualPress\'s translations yet', function (): void {
    $this->site->with('languages', ['api' => 1, 'active' => false, 'others' => [
        'translatepress-multilingual' => ['name' => 'TranslatePress', 'languages' => 3],
        'multilingualpress' => ['name' => 'MultilingualPress', 'languages' => 2],
    ]]);

    $stays = collect(app(ImportAnalysis::class)->read($this->source->id)->toArray()['stays'])->keyBy('key');

    expect($stays->keys()->filter(fn (string $key): bool => str_starts_with($key, 'languages.'))->values()->all())->toBe(['languages.other.translatepress-multilingual', 'languages.other.multilingualpress'])
        ->and($stays['languages.other.translatepress-multilingual'])->toMatchArray(['label' => 'TranslatePress', 'wordpressKey' => 'translatepress-multilingual', 'count' => 3]);
});

it('says the default language\'s addresses lose their first part when Polylang showed it', function (): void {
    $this->site->with('languages', PolylangSite::languages(['options' => ['force_lang' => 1, 'hide_default' => false]]));

    expect(lnKeys($this->source->id))->toBe(['languages.default-prefixed']);
});
