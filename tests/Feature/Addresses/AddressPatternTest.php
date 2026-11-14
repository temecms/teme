<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressPattern;

$values = ['slug' => 'my-post', 'year' => '2026', 'month' => '09', 'day' => '07', 'id' => '01J00000000000000000000XYZ', 'category' => 'news/local', 'author' => 'jane-doe'];

it('renders each preset', function () use ($values): void {
    expect(AddressPattern::parse(AddressPattern::POST_NAME)->render($values))->toBe('my-post')
        ->and(AddressPattern::parse(AddressPattern::DAY_AND_NAME)->render($values))->toBe('2026/09/07/my-post')
        ->and(AddressPattern::parse(AddressPattern::MONTH_AND_NAME)->render($values))->toBe('2026/09/my-post')
        ->and(AddressPattern::parse(AddressPattern::NUMERIC)->render($values))->toBe('archives/01j00000000000000000000xyz');
});

it('renders a custom pattern with the category path and the author', function () use ($values): void {
    expect(AddressPattern::parse('/{category}/{slug}/')->render($values))->toBe('news/local/my-post')
        ->and(AddressPattern::parse('/by/{author}/{slug}')->render($values))->toBe('by/jane-doe/my-post');
});

it('follows the pattern for the trailing slash', function (): void {
    expect(AddressPattern::parse('/{slug}/')->trailingSlash())->toBeTrue()
        ->and(AddressPattern::parse('/{slug}')->trailingSlash())->toBeFalse();
});

it('refuses patterns that could not identify a post', function (string $pattern): void {
    expect(fn () => AddressPattern::parse($pattern))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown token' => '/{postname}/',
    'no slug or id' => '/{year}/{month}/',
    'bad characters' => '/{slug}.html?x=1',
    'empty' => '',
]);
