<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks the admin’s tab and phone icon with Teme’s icon, each a file that exists', function (): void {
    $page = $this->get(route('login'))->assertOk()->getContent();

    foreach (['/teme/icon.ico', '/teme/icon.svg', '/teme/apple-touch-icon.png'] as $file) {
        expect($page)->toContain('href="'.$file.'"')
            ->and(public_path(ltrim($file, '/')))->toBeFile();
    }

    expect($page)->not->toContain('href="/favicon.svg"')
        ->and($page)->not->toContain('href="/apple-touch-icon.png"');
});

it('draws the tab icon for both schemes in one file, as the light and dark masters have it', function (): void {
    $svg = (string) file_get_contents(public_path('teme/icon.svg'));

    expect($svg)->toContain('prefers-color-scheme: dark')
        ->and($svg)->toContain('#0A6273')
        ->and($svg)->toContain('#48ADC2');
});

it('serves no one’s mark at /favicon.ico, so a site shows no icon until it has its own (I-1390)', function (): void {
    // Empty, as Laravel ships it: browsers that ask for it find no mark and no 404, and Teme's icon never reaches a site.
    expect(public_path('favicon.ico'))->toBeFile()
        ->and(filesize(public_path('favicon.ico')))->toBe(0);
});
