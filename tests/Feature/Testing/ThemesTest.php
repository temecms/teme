<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Teme\Testing\InteractsWithTeme;

uses(RefreshDatabase::class, InteractsWithTeme::class);

it('checks a theme as teme:theme:check does, with no problem for the handbook’s first theme', function (): void {
    expect($this->theme(base_path('docs/guides/developers/examples/themes/first-theme'))->check())->toBe([]);
});

it('activates a theme, which then draws the site', function (): void {
    $this->publish('Drawn post');

    $this->fetch('/')->assertDontSee('data-template="index"', false);

    $this->theme(base_path('docs/guides/developers/examples/themes/first-theme'))->activate();

    $this->fetch('/')->assertOk()->assertSee('data-template="index"', false);
    $this->fetch('/drawn-post/')->assertOk()->assertSee('data-template="single"', false);
});

it('fails the test when no theme has the code', function (): void {
    expect(fn () => $this->theme('no-such-theme'))->toThrow(AssertionFailedError::class, 'No theme has the code "no-such-theme"');
});
