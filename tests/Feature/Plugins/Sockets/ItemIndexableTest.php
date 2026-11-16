<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Hooks\Payloads;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\WholeSite;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Content\ItemRef;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');

    // The item page's own robots meta, as the public head writes it.
    $this->robots = fn (string $uri): string => headValue(headDom($uri), '//head/meta[@name="robots"]/@content');
});

it('says a published item search engines may list is indexable, as its page says', function (): void {
    $item = Payloads::item(headPost($this->author, 'Harbour news'));

    expect($item->indexable)->toBeTrue()
        ->and(($this->robots)('/harbour-news/'))->not->toContain('noindex');
});

it('says an item is not indexable when its page says noindex', function (string $case): void {
    $content = headPost($this->author, 'Harbour news', $case === 'its own box' ? [BuiltInFields::NOINDEX => true] : []);

    match ($case) {
        'its type hidden' => cmSettings(['hide_type_post' => true], SearchAndSocial::KEY),
        'the site discouraged' => cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY),
        default => null,
    };

    expect(Payloads::item($content->refresh())->indexable)->toBeFalse()
        ->and(($this->robots)('/harbour-news/'))->toContain('noindex');
})->with(['its own box', 'its type hidden', 'the site discouraged']);

it('says a draft, which has no address, is not indexable', function (): void {
    $item = Payloads::item(lifecycleDraft($this->author, 'Not yet'));

    expect($item->url)->toBeNull()
        ->and($item->indexable)->toBeFalse();
});

it('adds indexable last, true by default, so code that builds an ItemRef keeps working', function (): void {
    $parameters = (new ReflectionMethod(ItemRef::class, '__construct'))->getParameters();
    $last = $parameters[array_key_last($parameters)];

    expect($last->getName())->toBe('indexable')
        ->and($last->getDefaultValue())->toBeTrue()
        ->and((string) (new ReflectionMethod(ItemRef::class, '__construct'))->getDocComment())->toContain('@since 0.9.0')
        ->and((new ItemRef('01', 'post', 'A title', 'published', 'https://example.test/a/', 'en'))->indexable)->toBeTrue();
});
