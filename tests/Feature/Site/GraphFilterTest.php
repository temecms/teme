<?php

declare(strict_types=1);

use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\Precedence;
use App\Domain\Site\Document\Withheld;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    cmItem(User::factory()->create()->assignRole('author'), 'Harbour news');
    $this->page = 'https://example.test/harbour-news/';
});

it('runs the graph\'s filters once over the merged graph, plugin first, each given what the one before left, and drops references to what they remove (I-697)', function (): void {
    headWith(Precedence::Plugin, [new JsonLdNode(['@id' => $this->page.'#article', '@type' => 'Article', 'headline' => 'Harbour news', 'about' => ['@id' => $this->page.'#thing']])]);
    headWith(Precedence::Core, [new JsonLdNode(['@id' => $this->page.'#thing', '@type' => 'Thing', 'name' => 'The harbour'])]);
    $seen = [];

    sdFilterWith(Precedence::Site, function (array $graph) use (&$seen): array {
        $seen[] = 'site, after '.(sdNode($graph, $this->page.'#article')['alternativeHeadline'] ?? 'nothing');

        return array_values(array_filter($graph, fn (array $node): bool => $node['@id'] !== $this->page.'#thing'));
    });
    sdFilterWith(Precedence::Plugin, function (array $graph) use (&$seen): array {
        $seen[] = 'plugin';

        return array_map(fn (array $node): array => $node['@id'] === $this->page.'#article' ? [...$node, 'alternativeHeadline' => 'From a plugin'] : $node, $graph);
    });

    $graph = sdGraph('/harbour-news/');

    expect($seen)->toBe(['plugin', 'site, after From a plugin'])
        ->and(sdNode($graph, $this->page.'#thing'))->toBeNull()
        ->and(sdNode($graph, $this->page.'#article'))->toBe(['@id' => $this->page.'#article', '@type' => 'Article', 'headline' => 'Harbour news', 'alternativeHeadline' => 'From a plugin']);
});

it('holds what a filter returns to a node\'s rules, so the encoder can never fail on it (I-697)', function (): void {
    sdFilterWith(Precedence::Plugin, fn (array $graph): array => [...$graph, ['@type' => 'Thing', 'name' => 'Nowhere']]);

    expect(fn () => app(DocumentMeta::class)->for(new DocumentPage(null, null)))
        ->toThrow(InvalidArgumentException::class, 'A JSON-LD node needs an @id, an absolute address.');
});

it('leaves out a node that lacks what Google requires, and gives the head the reason, for the editor\'s checks (I-703)', function (): void {
    $video = $this->page.'#video-01K6A0P1CE000000000000VD01';
    headWith(Precedence::Plugin, [new JsonLdNode(['@id' => $video, '@type' => 'VideoObject', 'name' => 'The harbour at dawn', 'contentUrl' => 'https://example.test/media/dawn.mp4'])]);

    $head = app(DocumentMeta::class)->for(new DocumentPage(null, null));

    expect(array_column($head->graph, '@id'))->not->toContain($video)
        ->and($head->withheld)->toEqual([new Withheld($video, ['VideoObject'], [['thumbnailUrl'], ['uploadDate']])])
        ->and(sdNode(sdGraph('/harbour-news/'), $video))->toBeNull();
});
