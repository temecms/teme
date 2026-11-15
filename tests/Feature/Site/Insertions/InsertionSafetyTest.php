<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
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
