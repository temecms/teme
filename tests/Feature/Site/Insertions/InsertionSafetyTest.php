<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Site\Insertions\InsertionCleaner;
use App\Domain\Site\Insertions\InsertionPoints;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\InsertionContributor;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Insertions\Position;
use Teme\Contracts\Operations\OperationInput;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->folder = cacheOn();

    $this->post = pageArticleFromDocument($this->author, 'Safety story', [
        docText('Paragraph one.'),
        docText('Paragraph two.'),
        docText('Paragraph three.'),
    ]);

    $name = 'test.insertions.unsafe.'.uniqid();
    app()->instance($name, new readonly class implements InsertionContributor
    {
        public function precedence(): string
        {
            return 'plugin';
        }

        public function insertions(ArticleFacts $article): array
        {
            return [new Insertion(
                'unsafe.ad',
                Position::afterParagraph(2),
                '<aside data-test="unsafe" onclick="evil()"><script>alert(1)</script><script src="https://ads.example/a.js"></script>Ad</aside>',
            )];
        }
    });
    app()->tag([$name], InsertionPoints::CONTRIBUTORS);
});

afterEach(function (): void {
    if (isset($this->folder)) {
        cacheOff($this->folder);
    }
});

it('draws an insertion without inline scripts and onclick, and adds its script src origin to script-src', function (): void {
    $response = addrGet('/safety-story/')->assertOk();
    $html = (string) $response->getContent();
    $policy = PolicyHeaders::of($response);

    expect($html)->toContain('data-test="unsafe"')
        ->toContain('<script src="https://ads.example/a.js"')
        ->not->toContain('onclick=')
        ->not->toContain('alert(1)')
        ->and(PolicyHeaders::sources($policy, 'script-src'))->toContain('https://ads.example');
});

it('purges insertions pages after InsertionsChanged::notify() commits, and not on rollback', function (): void {
    addrGet('/safety-story/')->assertOk();
    expect(cacheEntry('/safety-story/')?->tags)->toContain(PageTags::INSERTIONS);

    $failing = new class extends BaseOperation
    {
        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            app(InsertionsChanged::class)->notify();

            throw new RuntimeException('perform() failed on purpose.');
        }
    };

    expect(fn () => $failing->execute(schedProbeInput()))->toThrow(RuntimeException::class)
        ->and(cacheEntry('/safety-story/'))->not->toBeNull();

    $ok = new class extends BaseOperation
    {
        protected function authorize(OperationInput $input): void {}

        protected function validate(OperationInput $input): void {}

        protected function perform(OperationInput $input): mixed
        {
            app(InsertionsChanged::class)->notify();

            return null;
        }
    };

    $ok->execute(schedProbeInput());

    expect(cacheEntry('/safety-story/'))->toBeNull();
});

it('removes a script without src in every namespace, SVG and MathML included, and every on* attribute there', function (string $html, string $gone): void {
    $cleaned = InsertionCleaner::clean($html)['html'];

    expect($cleaned)->not->toContain($gone);
})->with([
    'an SVG script' => ['<svg><script>alert("svg")</script></svg>', 'alert("svg")'],
    'a MathML script' => ['<math><script>alert("math")</script></math>', 'alert("math")'],
    'an SVG script by href' => ['<svg><script href="https://evil.example/x.js"></script></svg>', 'evil.example'],
    'an on* attribute on SVG' => ['<svg onload="alert(1)"><circle r="1" onclick="alert(2)"></circle></svg>', 'alert('],
    'an on* attribute on MathML' => ['<math onclick="alert(3)"><mi>x</mi></math>', 'alert('],
]);

it('drops srcdoc and any address that starts with javascript: or data:text/html', function (string $html, string $gone): void {
    $cleaned = InsertionCleaner::clean($html)['html'];

    expect(strtolower($cleaned))->not->toContain($gone);
})->with([
    'href' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
    'href, spaced and mixed case' => ['<a href="  JavaScript:alert(1)">x</a>', 'javascript:'],
    'href with a tab inside the scheme' => ["<a href=\"java\tscript:alert(1)\">x</a>", 'alert(1)'],
    'src' => ['<iframe src="javascript:alert(1)"></iframe>', 'javascript:'],
    'data:text/html src' => ['<iframe src="data:text/html,<script>alert(1)</script>"></iframe>', 'data:text/html'],
    'srcdoc' => ['<iframe srcdoc="<b>x</b>"></iframe>', 'srcdoc'],
    'SVG href' => ['<svg><a href="javascript:alert(1)"><text>x</text></a></svg>', 'javascript:'],
    'SVG xlink:href' => ['<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>', 'javascript:'],
    'action' => ['<form action="javascript:alert(1)"><input></form>', 'javascript:'],
    'formaction' => ['<form><button formaction="data:text/html,x">Go</button></form>', 'data:text/html'],
    'SVG animation that sets href' => ['<svg><a><animate attributeName="href" values="javascript:alert(1)"></animate><text>x</text></a></svg>', 'javascript:'],
]);

it('keeps https addresses, script src and the markup around what it drops', function (): void {
    $cleaned = InsertionCleaner::clean('<a href="https://example.com/x">Link</a><img src="data:image/png;base64,AAAA" alt=""><script src="https://ads.example/a.js"></script>')['html'];

    expect($cleaned)->toContain('href="https://example.com/x"')
        ->toContain('src="data:image/png;base64,AAAA"')
        ->toContain('<script src="https://ads.example/a.js"></script>')
        ->toContain('>Link</a>');
});
