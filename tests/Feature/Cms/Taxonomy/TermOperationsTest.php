<?php

declare(strict_types=1);

use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Domain\Taxonomy\Queries\TermTree;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

function taxonomyCreate(User $actor, string $taxonomy, string $name, ?string $parentId = null, ?string $slug = null): Term
{
    return app(CreateTerm::class)->execute(new SaveTermInput($taxonomy, $name, $slug, $parentId, null, (string) $actor->id));
}

it('creates a category with a slug made from its name', function (): void {
    $term = taxonomyCreate($this->editor, 'category', 'Local News');

    expect($term->slug)->toBe('local-news')
        ->and($term->taxonomy)->toBe('category')
        ->and($term->published_count)->toBe(0);
});

it('suffixes a slug already used in the same taxonomy only', function (): void {
    taxonomyCreate($this->editor, 'category', 'News');

    expect(taxonomyCreate($this->editor, 'category', 'News')->slug)->toBe('news-2')
        ->and(taxonomyCreate($this->editor, 'tag', 'News')->slug)->toBe('news');
});

it('saves a long Chinese slug on create and on rename, cut only between characters', function (): void {
    $long = str_repeat('猫', 200);
    $slug = str_repeat('猫', 100);
    $other = 'a'.str_repeat('猫', 99);

    $term = taxonomyCreate($this->editor, 'category', 'Cats', null, $slug);
    $renamed = app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Cats', $other, null, null, (string) $this->editor->id, $term->id));

    expect($term->slug)->toBe($slug)
        ->and(mb_check_encoding($term->fresh()->slug, 'UTF-8'))->toBeTrue()
        ->and($renamed->slug)->toBe($other);
    expect(fn () => taxonomyCreate($this->editor, 'category', 'Too long', null, $long))->toThrow(ValidationException::class);
});

it('refuses a parent on a tag, and a parent from another taxonomy', function (): void {
    $category = taxonomyCreate($this->editor, 'category', 'News');
    $tag = taxonomyCreate($this->editor, 'tag', 'Breaking');

    expect(fn () => taxonomyCreate($this->editor, 'tag', 'Child', $category->id))->toThrow(ValidationException::class)
        ->and(fn () => taxonomyCreate($this->editor, 'category', 'Child', $tag->id))->toThrow(ValidationException::class);
});

it('refuses to make a category its own ancestor', function (): void {
    $parent = taxonomyCreate($this->editor, 'category', 'Parent');
    $child = taxonomyCreate($this->editor, 'category', 'Child', $parent->id);

    expect(fn () => app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Parent', 'parent', $child->id, null, (string) $this->editor->id, $parent->id)))
        ->toThrow(ValidationException::class);
});

it('finds a category and all its descendants', function (): void {
    $a = taxonomyCreate($this->editor, 'category', 'A');
    $b = taxonomyCreate($this->editor, 'category', 'B', $a->id);
    $c = taxonomyCreate($this->editor, 'category', 'C', $b->id);
    taxonomyCreate($this->editor, 'category', 'Other');

    expect(app(TermTree::class)->descendantIds($a->id))->toEqualCanonicalizing([$a->id, $b->id, $c->id])
        ->and(app(TermTree::class)->paths([$c->id]))->toBe([$c->id => 'A › B › C']);
});

it('moves a deleted category\'s children up to its parent', function (): void {
    $a = taxonomyCreate($this->editor, 'category', 'A');
    $b = taxonomyCreate($this->editor, 'category', 'B', $a->id);
    $c = taxonomyCreate($this->editor, 'category', 'C', $b->id);

    app(DeleteTerm::class)->execute(new DeleteTermInput($b->id, (string) $this->editor->id));

    expect(Term::query()->find($b->id))->toBeNull()
        ->and($c->fresh()->parent_id)->toBe($a->id);
});

it('writes nothing for someone who may not manage terms', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $term = taxonomyCreate($this->editor, 'category', 'News');

    expect(fn () => taxonomyCreate($author, 'category', 'Mine'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Renamed', 'renamed', null, null, (string) $author->id, $term->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteTerm::class)->execute(new DeleteTermInput($term->id, (string) $author->id)))->toThrow(AuthorizationException::class);

    expect(Term::query()->count())->toBe(1)
        ->and($term->fresh()->name)->toBe('News');
});
