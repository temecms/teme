<?php

declare(strict_types=1);

use App\Http\Standalone\StandaloneStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('/admin/_sa-error/{code}', fn (int $code) => abort($code));
        Route::post('/admin/_sa-error/{code}', fn (int $code) => abort($code));
        Route::get('/admin/_sa-refused', fn () => abort(403, request()->string('reason')->toString()));
        Route::get('/admin/_sa-maintenance', fn () => abort(503, '', ['Retry-After' => '60']));
        Route::post('/admin/_sa-maintenance', fn () => abort(503, '', ['Retry-After' => '60']));
    });

    cmSettings(['site_title' => 'The Garden City Ledger'], 'general');
});

/** The card's own rules: the component's style text after the token block. */
function saCardRules(): string
{
    $source = (string) file_get_contents(resource_path('views/teme/components/standalone.blade.php'));
    preg_match('#<style>(.*?)</style>#s', $source, $style);

    return substr($style[1], (int) strpos($style[1], '!!}') + 3);
}

/** The page parsed, for its elements. */
function saPage(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

    return new DOMXPath($document);
}

it('turns the admin\'s token block into plain CSS', function (): void {
    $tokens = app(StandaloneStyle::class)->tokens();

    expect($tokens)->toContain('--page:')
        ->toContain('--text-body: 0.9375rem')
        ->toContain('--radius-xl: 16px')
        ->toContain('--accent-l:')
        ->toContain('@media (prefers-color-scheme: dark){:root{');

    foreach (['@theme', '.dark', '--color-', '@keyframes', '/*'] as $gone) {
        expect($tokens)->not->toContain($gone);
    }
});

it('declares every value the card reads', function (): void {
    $tokens = app(StandaloneStyle::class)->tokens();
    preg_match_all('/var\(--([a-z0-9-]+)\)/', saCardRules(), $read);

    expect($read[1])->not->toBeEmpty();

    foreach (array_unique($read[1]) as $name) {
        expect($tokens)->toContain("--{$name}:");
    }
});

it('writes no color and no size of its own in the card\'s rules', function (): void {
    $rules = saCardRules();

    expect($rules)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->and($rules)->not->toMatch('/\b(rgb|hsl|oklch)\(/')
        ->and($rules)->not->toMatch('/(?<![\w-])(white|black)(?![\w-])/');

    preg_match_all('/\bfont(?:-size)?\s*:([^;}]*)/', $rules, $sizes);

    expect($sizes[1])->not->toBeEmpty();

    foreach ($sizes[1] as $value) {
        expect($value)->toContain('var(--text-');
    }
});

it('keeps an empty stylesheet, not an error, when the markers are gone', function (): void {
    $source = str_replace('/* @end-tokens */', '', (string) file_get_contents(resource_path('css/app.css')));

    expect(app(StandaloneStyle::class)->build('body{}'))->toBe('')
        ->and(app(StandaloneStyle::class)->build($source))->toBe('');
});

it('draws each error on the card with the site\'s mark and its own words', function (int $code, string $heading): void {
    $response = $this->get("/admin/_sa-error/{$code}")->assertStatus($code);
    $html = (string) $response->getContent();
    $page = saPage($html);

    expect($page->query("//main[@data-error='{$code}']")->length)->toBe(1)
        ->and($page->query('//h1')->length)->toBe(1)
        ->and(trim((string) $page->query('//h1')->item(0)?->textContent))->toBe($heading)
        ->and(trim((string) $page->query("//*[@class='sa-name']")->item(0)?->textContent))->toBe('The Garden City Ledger')
        ->and(trim((string) $page->query("//*[@class='sa-mark']")->item(0)?->textContent))->toBe('T')
        ->and($page->query("//a[@class='sa-button']")->item(0)?->getAttribute('href'))->toBe(route('dashboard'))
        ->and(trim((string) $page->query("//a[@class='sa-button']")->item(0)?->textContent))->toBe('Back to Home')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<link')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('Powered by Teme');
})->with([
    [403, 'You can’t open this page'],
    [404, 'We can’t find that page'],
    [419, 'This page has expired'],
    [429, 'Too many tries'],
    [500, 'Something went wrong on our side'],
    [503, 'Down for maintenance'],
]);

it('says a refusal\'s own reason on the 403 page', function (): void {
    $page = saPage((string) $this->get('/admin/_sa-refused?'.http_build_query(['reason' => 'You can’t edit this item.']))->assertForbidden()->getContent());

    expect(trim((string) $page->query("//*[@class='sa-quiet']")->item(0)?->textContent))->toBe('You can’t edit this item.');
});

it('says the general words on the 403 page when the refusal gives only Laravel\'s own', function (): void {
    $page = saPage((string) $this->get('/admin/_sa-refused?'.http_build_query(['reason' => 'This action is unauthorized.']))->assertForbidden()->getContent());

    expect(trim((string) $page->query("//*[@class='sa-quiet']")->item(0)?->textContent))
        ->toBe('Your account doesn’t have permission to do that. If you need it, ask an administrator of The Garden City Ledger to change your role.');
});

it('keeps Laravel\'s status and Retry-After, and refreshes a GET only', function (): void {
    $this->get('/admin/_sa-maintenance')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertSee('<meta http-equiv="refresh" content="20">', false);

    $this->post('/admin/_sa-maintenance')
        ->assertStatus(503)
        ->assertDontSee('http-equiv="refresh"', false);
});

it('draws any other code by its class', function (): void {
    $this->get('/admin/_sa-error/405')->assertStatus(405)
        ->assertSee('data-error="405"', false)
        ->assertSee('This page can’t be shown');

    $this->get('/admin/_sa-error/502')->assertStatus(502)
        ->assertSee('data-error="502"', false)
        ->assertSee('Something went wrong on our side');
});

it('draws Teme\'s mark when the site\'s name cannot be read', function (): void {
    $this->mock(SettingsReader::class)
        ->shouldReceive('string')
        ->andThrow(new RuntimeException('The database is gone.'));

    $html = (string) $this->get('/admin/_sa-error/500')->assertStatus(500)->getContent();
    $page = saPage($html);

    expect($page->query("//main[@data-error='500']")->length)->toBe(1)
        ->and($html)->toContain('<svg aria-hidden="true"')
        ->and($page->query("//*[@class='sa-name']")->length)->toBe(0)
        ->and(trim((string) $page->query("//*[@class='sa-brand']/*[@class='sa-product']")->item(0)?->textContent))->toBe('Teme');
});

it('leaves the public site\'s errors to the theme', function (): void {
    $this->get('/no-such-page-here')
        ->assertStatus(404)
        ->assertDontSee('data-error=', false);
});
