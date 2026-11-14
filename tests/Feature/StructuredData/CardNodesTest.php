<?php

declare(strict_types=1);

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;
use App\Domain\Cards\StructuredDataCard;
use App\Domain\Cards\Types\ImageCard;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\StructuredData\CardNodes;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\StructuredData\NodeIds;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->episode = fn (string $kind): array => ['type' => 'podcast', 'attrs' => ['id' => '01K6A0P1CE000000000000PD01', 'title' => 'The harbour hour', 'kind' => $kind]];
    $this->card = new class implements CardType, StructuredDataCard
    {
        public function name(): string
        {
            return 'podcast';
        }

        public function label(): string
        {
            return 'Podcast';
        }

        public function description(): string
        {
            return 'An episode, played in the article.';
        }

        public function since(): string
        {
            return '0.1.0';
        }

        public function attributes(): array
        {
            return [
                'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
                'title' => new CardAttribute('title', ['required', 'string', 'max:200']),
                'kind' => new CardAttribute('kind', ['required', 'in:audio,video']),
            ];
        }

        public function menu(): CardMenuEntry
        {
            return new CardMenuEntry('Podcast', 'Cards', ['podcast', 'episode'], 'audio');
        }

        public function renderHtml(array $attrs, RenderContext $context): string
        {
            return '<p>'.e((string) ($attrs['title'] ?? '')).'</p>';
        }

        public function plainText(array $attrs): string
        {
            return (string) ($attrs['title'] ?? '');
        }

        public function references(array $attrs): array
        {
            return [];
        }

        public function validate(array $attrs): void
        {
            // Its attributes' rules say all there is to check.
        }

        public function describeChange(array $before, array $after): string
        {
            return 'Podcast: its episode changed';
        }

        public function structuredData(array $attrs, string $page): array
        {
            return [new JsonLdNode([
                '@id' => $page.NodeIds::card('podcast', (string) $attrs['id']),
                '@type' => ($attrs['kind'] ?? '') === 'video' ? 'VideoObject' : 'AudioObject',
                'name' => (string) $attrs['title'],
                'contentUrl' => 'https://media.example/harbour-hour.mp3',
            ])];
        }
    };
});

it('adds the nodes a card in the item gives for itself, at the page\'s address and the card\'s own ID (I-697, I-704)', function (): void {
    app(CardRegistry::class)->register($this->card);
    headPost($this->author, 'Harbour news', [], [], [($this->episode)('audio')]);

    expect(sdNode(sdGraph('/harbour-news/'), 'http://localhost/harbour-news/#podcast-01K6A0P1CE000000000000PD01'))->toEqual([
        '@id' => 'http://localhost/harbour-news/#podcast-01K6A0P1CE000000000000PD01',
        '@type' => 'AudioObject',
        'name' => 'The harbour hour',
        'contentUrl' => 'https://media.example/harbour-hour.mp3',
    ]);
});

it('leaves out a card\'s node that lacks what Google requires, as a video with no poster, and keeps the page (I-703, I-704)', function (): void {
    app(CardRegistry::class)->register($this->card);
    headPost($this->author, 'Harbour news', [], [], [($this->episode)('video')]);

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, 'http://localhost/harbour-news/#podcast-01K6A0P1CE000000000000PD01'))->toBeNull()
        ->and(sdNode($graph, 'http://localhost/harbour-news/'))->not->toBeNull();
});

it('reads nothing for a page while no registered card gives nodes (I-704)', function (): void {
    $view = app(ArticleViews::class)->published(headPost($this->author, 'Harbour news'));
    $registry = new CardRegistry;
    $registry->register(new ImageCard);

    expect(dashQueries(fn () => (new CardNodes($registry))->for($view, 'http://localhost/harbour-news/')))->toBe([]);
});
