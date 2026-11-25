<?php

/**
 * The editor's Address popover: tidying on leave, emptying to the title,
 * and the popover's own words and controls.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

it('tidies a typed address the way WordPress cleans a slug', function (): void {
    expect(guardCall('components/editor/tidy-slug.ts', 'tidySlug', [
        ['When the new fares start'],
        ['-temecms--1-0'],
        ['Café Menu'],
        ['suvs-with-the-highest-death-rates/'],
        ['https://example.org/harbour-festival/'],
        ['  Hello   World  '],
        ['user@harbour'],
    ]))->toBe([
        'when-the-new-fares-start',
        'temecms-1-0',
        'cafe-menu',
        'suvs-with-the-highest-death-rates',
        'harbour-festival',
        'hello-world',
        'user-at-harbour',
    ]);
});

it('gives an emptied address the slug from the title, or untitled', function (): void {
    expect(guardCall('components/editor/tidy-slug.ts', 'slugFromTitle', [
        ['Harbour festival'],
        ['!!!'],
        [''],
    ]))->toBe([
        'harbour-festival',
        'untitled',
        'untitled',
    ]);
});

it('draws the address popover headed with close, copy, and no Change address button', function (): void {
    $panel = uiComponent('components/editor/rail/publish-panel.tsx');

    expect($panel)
        ->toContain('data-test="address-popover"')
        ->toContain('data-test="address-close"')
        ->toContain('data-test="address-copy"')
        ->toContain('Copy the address')
        ->toContain('Address copied')
        ->toContain("The last part of this post's address.")
        ->toContain('The old address will keep sending readers here once you update.')
        ->toContain('tidySlug')
        ->toContain('slugFromTitle')
        ->not->toContain('Change address');
});

it('saves a pending address with the post, not from a Change address button', function (): void {
    $edit = uiComponent('pages/content/edit.tsx');

    expect($edit)
        ->toContain('saveAddressIfNeeded')
        ->toContain('sidePending: slug !== savedSlug')
        ->toContain('Another post already uses this address. Add a word to make it its own.');
});
