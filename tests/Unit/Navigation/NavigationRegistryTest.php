<?php

declare(strict_types=1);

use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavigationRegistry;
use App\Domain\Navigation\NavSection;

// uiNavEntry() lives in tests/Pest.php.

it('refuses two entries with one key', function (): void {
    $registry = new NavigationRegistry;
    $registry->register(uiNavEntry('posts'));

    expect(fn () => $registry->register(uiNavEntry('posts')))->toThrow(InvalidArgumentException::class);
});

it('refuses a parent it does not know', function (): void {
    expect(fn () => (new NavigationRegistry)->register(uiNavEntry('drafts', parent: 'posts')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a third level, which belongs inside the page', function (): void {
    $registry = new NavigationRegistry;
    $registry->register(uiNavEntry('posts'));
    $registry->register(uiNavEntry('drafts', parent: 'posts'));

    expect(fn () => $registry->register(uiNavEntry('old-drafts', parent: 'drafts')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses an entry that neither links anywhere nor opens a hub', function (): void {
    expect(fn () => (new NavigationRegistry)->register(new NavEntry('x', 'X', 'file', NavSection::Site, 100)))
        ->toThrow(InvalidArgumentException::class);
});
