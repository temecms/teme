<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('frames a Vimeo video with its do-not-track switch on, as YouTube\'s frame is its privacy-friendly one (I-446)', function (): void {
    $html = app(DocumentRenderer::class)->html(docWith(['type' => 'embed', 'attrs' => [
        'id' => '01K6A0D1000000000000000EM1', 'url' => 'https://vimeo.com/76979871', 'kind' => 'video',
        'provider' => 'vimeo', 'title' => 'The harbour at dawn', 'thumbnailMediaId' => null, 'caption' => null,
    ]]));

    expect($html)->toContain('<iframe src="https://player.vimeo.com/video/76979871?dnt=1"');
});

it('gives the editor the same frame address (I-446)', function (): void {
    $vimeo = collect(CardManifest::forBrowser(app(CardRegistry::class))['embedProviders'])->firstWhere('name', 'vimeo');

    expect($vimeo['frame'] ?? null)->toBe('https://player.vimeo.com/video/$1?dnt=1');
});
