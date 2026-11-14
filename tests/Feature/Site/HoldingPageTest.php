<?php

declare(strict_types=1);

use App\Domain\Users\SetupCode;
use App\Http\Middleware\HoldUntilSetUp;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // The suite runs with the hold off (tests/TestCase.php); these tests are about it.
    $this->withMiddleware(HoldUntilSetUp::class);
});

it('answers every public page with the holding page while no account exists, drawn by core, not the theme', function (string $path): void {
    $response = $this->get($path)
        ->assertStatus(503)
        ->assertHeader('Retry-After', (string) HoldUntilSetUp::RETRY_AFTER)
        ->assertSee('This site is being set up and will open soon.')
        ->assertSee('Is this your site?')
        ->assertSee(route('first-run.show'))
        ->assertSee(parse_url((string) config('app.url'), PHP_URL_HOST))
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('<main data-holding>', false)
        ->assertDontSee(app(SetupCode::class)->forThisSite());

    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
})->with(['/', '/hello-world/', '/category/news/', '/2026/11/12/']);

it('answers a HEAD the same way', function (): void {
    $this->call('HEAD', '/')->assertStatus(503)->assertHeader('Retry-After', (string) HoldUntilSetUp::RETRY_AFTER);
});

it('answers feeds, sitemaps, robots.txt, llms.txt and the .md copies in plain text', function (string $path): void {
    $response = $this->get($path)
        ->assertStatus(503)
        ->assertHeader('Retry-After', (string) HoldUntilSetUp::RETRY_AFTER);

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->getContent())->toBe('This site is being set up and will open soon.'.PHP_EOL);
})->with(['/robots.txt', '/feed/', '/sitemap.xml', '/llms.txt', '/hello-world.md']);

it('answers the API with a problem, never the page', function (string $path): void {
    $this->getJson($path)
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Retry-After', (string) HoldUntilSetUp::RETRY_AFTER)
        ->assertJson(['status' => 503, 'code' => 'unavailable', 'detail' => 'This site is being set up. Try again later.']);
})->with(['/api/v1/items', '/api/nothing-here']);

it('leaves the health check, the setup page and the sign-in page alone', function (): void {
    $this->get('/up')->assertOk();
    $this->get(route('first-run.show'))->assertOk();
    $this->get(route('login'))->assertOk();
});

it('is never stored, and gives way to the site as soon as an account exists', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $folder = cacheOn();

    try {
        addrGet('/')->assertStatus(503)->assertHeader('X-Teme-Cache', 'BYPASS (server error)');
        addrGet('/')->assertStatus(503)->assertHeader('X-Teme-Cache', 'BYPASS (server error)');

        // Any account ends the hold (D2), whether the setup page, teme:user:create or an import made it.
        User::factory()->create();

        addrGet('/')->assertOk();
    } finally {
        cacheOff($folder);
    }
});

it('draws the approved picture: the mark, the address, the heading, a rule, the owner’s notice with its three parts, then the foot line, in that order', function (): void {
    $html = (string) $this->get('/')->assertStatus(503)->getContent();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $children = fn (DOMNode $node): array => array_values(array_map(
        fn (DOMElement $element): string => $element->tagName.($element->getAttribute('class') === '' ? '' : '.'.$element->getAttribute('class')),
        array_filter(iterator_to_array($node->childNodes), fn (DOMNode $child): bool => $child instanceof DOMElement),
    ));

    $main = $xpath->query('//main[@data-holding]')->item(0);
    $card = $xpath->query('//main[@data-holding]/div[@class="sa-notice"]')->item(0);
    $style = (string) $xpath->query('//style')->item(0)?->textContent;

    expect($main)->not->toBeNull()
        ->and($children($main))->toBe(['div.sa-brand', 'p.hold-addr', 'h1', 'hr', 'div.sa-notice', 'p'])
        ->and($card)->not->toBeNull()
        ->and($children($card))->toBe(['p', 'p', 'a.sa-button'])
        ->and($style)->toContain('width: min(420px, 100%)')
        ->and($style)->toContain('@media (prefers-color-scheme: dark){:root{');
});
