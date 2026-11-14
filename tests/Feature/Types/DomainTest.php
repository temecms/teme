<?php

declare(strict_types=1);

use App\Domain\Taxonomy\TermProjection;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    typesFresh();
    app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics', hierarchical: true));
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events', taxonomies: ['topic']));
});

it('creates a draft of any registered type, and refuses an unknown one', function (): void {
    $event = typesDraft($this->editor, 'event', 'Harbour festival');

    expect($event->type)->toBe('event')
        ->and(fn () => typesDraft($this->editor, 'recipe', 'Soup'))->toThrow(ValidationException::class);
});

it('allows only the taxonomies the type uses', function (): void {
    $topic = taxonomyTerm('topic', 'Boats');
    $tag = taxonomyTerm('tag', 'Herons');

    expect(fn () => typesDraft($this->editor, 'event', 'Regatta', [$topic->id]))->not->toThrow(Throwable::class)
        ->and(fn () => typesDraft($this->editor, 'event', 'Regatta two', [$tag->id]))->toThrow(ValidationException::class)
        ->and(fn () => typesDraft($this->editor, 'post', 'News', [$topic->id]))->toThrow(ValidationException::class)
        ->and(fn () => typesDraft($this->editor, 'page', 'About', [$tag->id]))->toThrow(ValidationException::class);
});

it('ignores a page\'s old categories and tags, without rewriting its history (I-417)', function (): void {
    $tag = taxonomyTerm('tag', 'Harbour');
    $page = typesDraft($this->editor, 'page', 'About');

    // A page tagged before 1.5·0: its revision lists the tag, as old revisions may.
    DB::table('content_revisions')->where('id', $page->working_revision_id)->update(['terms' => json_encode([$tag->id])]);
    TermProjection::sync($page->refresh());

    expect(DB::table('content_terms')->where('content_id', $page->id)->count())->toBe(0)
        ->and(json_decode((string) DB::table('content_revisions')->where('id', $page->working_revision_id)->value('terms'), true))->toBe([$tag->id]);
});

it('lets hierarchical taxonomies nest, within themselves, and flat ones not', function (): void {
    $parent = typesTerm($this->editor, 'topic', 'Boats');
    $child = typesTerm($this->editor, 'topic', 'Lifeboats', $parent->id);
    $tag = typesTerm($this->editor, 'tag', 'Herons');
    $category = typesTerm($this->editor, 'category', 'News');

    expect($child->parent_id)->toBe($parent->id)
        ->and(fn () => typesTerm($this->editor, 'tag', 'Grey herons', $tag->id))->toThrow(ValidationException::class)
        ->and(fn () => typesTerm($this->editor, 'topic', 'Harbour news', $category->id))->toThrow(ValidationException::class)
        ->and(fn () => typesTerm($this->editor, 'genre', 'Jazz'))->toThrow(ValidationException::class);
});

it('asks the type which ability governs an action', function (): void {
    app(ContentTypeRegistry::class)->register(new ContentType('notice', 'Notice', 'Notices', 'notices', abilities: ['create' => 'notices.create']));
    Permission::findOrCreate('notices.create');

    expect(fn () => typesDraft($this->editor, 'notice', 'Road closed'))->toThrow(AuthorizationException::class)
        ->and(DB::table('contents')->where('type', 'notice')->count())->toBe(0);

    $this->editor->givePermissionTo('notices.create');
    expect(typesDraft($this->editor, 'notice', 'Road closed')->type)->toBe('notice');
});
