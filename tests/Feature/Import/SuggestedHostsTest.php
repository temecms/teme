<?php

declare(strict_types=1);

use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Queries\SuggestedHosts;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->fake = importFake();
    // FakeCompanion's probe always stores https://old.example as the home.
    $this->source = importSource($this->admin);
});

it('suggests subdomain hosts from the analysis, most links first, never another domain', function (): void {
    $this->fake->items('analyze', tiReport([
        'content' => [
            'html' => [
                'links' => [
                    'external_hosts' => [
                        'stage.old.example' => 143,
                        'cdn.old.example' => 3,
                        'other.org' => 50,
                    ],
                ],
            ],
        ],
    ]));
    app(ImportAnalysis::class)->read($this->source->id);

    expect(app(SuggestedHosts::class)->for($this->source))->toBe([
        ['host' => 'stage.old.example', 'links' => 143],
        ['host' => 'cdn.old.example', 'links' => 3],
    ]);
});

it('does not suggest a host already listed on the source', function (): void {
    $this->source->forceFill(['other_hosts' => ['stage.old.example']])->save();
    $this->fake->items('analyze', tiReport([
        'content' => [
            'html' => [
                'links' => [
                    'external_hosts' => [
                        'stage.old.example' => 143,
                        'cdn.old.example' => 3,
                        'other.org' => 50,
                    ],
                ],
            ],
        ],
    ]));
    app(ImportAnalysis::class)->read($this->source->id);

    expect(app(SuggestedHosts::class)->for($this->source->fresh()))->toBe([
        ['host' => 'cdn.old.example', 'links' => 3],
    ]);
});

it('gives no suggestions when the analysis has no external_hosts section', function (): void {
    $this->fake->items('analyze', tiReport(['content' => ['html' => []]]));
    app(ImportAnalysis::class)->read($this->source->id);

    expect(app(SuggestedHosts::class)->for($this->source))->toBe([]);
});
