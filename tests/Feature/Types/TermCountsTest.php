<?php

declare(strict_types=1);

use App\Domain\Taxonomy\TermCounts;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    typesFresh();
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events', taxonomies: ['tag']));
});

it('counts a term per type as well as in total', function (): void {
    $tag = taxonomyTerm('tag', 'Harbour');
    listsPublish(typesDraft($this->editor, 'post', 'A post', [$tag->id]), $this->editor);
    listsPublish(typesDraft($this->editor, 'event', 'An event', [$tag->id]), $this->editor);
    typesDraft($this->editor, 'event', 'A draft event', [$tag->id]);

    expect(app(TermCounts::class)->published($tag->id, 'post'))->toBe(1)
        ->and(app(TermCounts::class)->published($tag->id, 'event'))->toBe(1)
        ->and(app(TermCounts::class)->forTerm($tag->id)['event']['working'])->toBe(2)
        ->and($tag->refresh()->published_count)->toBe(2);
});

it('rebuilds per-type counts to the same numbers', function (): void {
    $tag = taxonomyTerm('tag', 'Harbour');
    listsPublish(typesDraft($this->editor, 'event', 'An event', [$tag->id]), $this->editor);
    $before = app(TermCounts::class)->forTerm($tag->id);

    DB::table('term_counts')->update(['published' => 0, 'working' => 0]);
    $this->artisan('teme:counts:rebuild', ['--check' => true])->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(app(TermCounts::class)->forTerm($tag->id))->toEqual($before);
});
