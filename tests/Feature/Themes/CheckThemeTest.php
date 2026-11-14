<?php

declare(strict_types=1);

beforeEach(fn () => config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/broken')]]));

it('passes a good theme and reports its asset sizes', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'good'])
        ->expectsOutputToContain('CSS')
        ->assertSuccessful();
});

it('fails a broken theme and lists every error', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'broken'])
        ->expectsOutputToContain('single-page-landing')
        ->assertFailed();
});
