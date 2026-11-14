<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

function emptyDocument(): array
{
    return ['type' => 'doc', 'content' => []];
}

it('creates a draft with a working revision and no published revision', function (): void {
    $user = User::factory()->create()->assignRole('author');

    $content = app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'My first article',
        document: emptyDocument(),
        fields: [BuiltInFields::SEO_TITLE => 'My first article'],
        actorId: (string) $user->id,
    ));

    expect($content)->toBeInstanceOf(Content::class)
        ->and($content->status)->toBe('draft')
        ->and($content->published_revision_id)->toBeNull()
        ->and($content->working_revision_id)->not->toBeNull()
        ->and($content->workingRevision->title)->toBe('My first article')
        ->and($content->workingRevision->fields)->toBe([BuiltInFields::SEO_TITLE => 'My first article'])
        ->and($content->workingRevision->origin)->toBe('human');
});

it('derives a unique slug when the title collides', function (): void {
    $user = User::factory()->create()->assignRole('author');

    $make = fn (): Content => app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Same title',
        document: emptyDocument(),
        fields: [],
        actorId: (string) $user->id,
    ));

    expect($make()->slug)->toBe('same-title')
        ->and($make()->slug)->toBe('same-title-2')
        ->and($make()->slug)->toBe('same-title-3');
});

it('uses the plain slug when it is free, even if a numbered-looking one exists', function (): void {
    $user = User::factory()->create()->assignRole('author');

    $make = fn (string $title): Content => app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: $title,
        document: emptyDocument(),
        fields: [],
        actorId: (string) $user->id,
    ));

    // "covid-19" must not be read as the 19th "covid".
    expect($make('Covid 19')->slug)->toBe('covid-19')
        ->and($make('Covid')->slug)->toBe('covid');
});

it('truncates a very long title so the slug and any suffix fit the column', function (): void {
    $user = User::factory()->create()->assignRole('author');
    $title = trim(str_repeat('word ', 100)); // 499 characters, inside the 512 limit

    $make = fn (): Content => app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: $title,
        document: emptyDocument(),
        fields: [],
        actorId: (string) $user->id,
    ));

    $first = $make();
    $second = $make();

    expect(strlen($first->slug))->toBeLessThanOrEqual(200)
        ->and($second->slug)->toBe($first->slug.'-2')
        ->and(strlen($second->slug))->toBeLessThanOrEqual(255);
});

it('refuses a user without content.create and writes nothing', function (): void {
    $user = User::factory()->create();

    expect(fn () => app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Should not exist',
        document: emptyDocument(),
        fields: [],
        actorId: (string) $user->id,
    )))->toThrow(AuthorizationException::class);

    expect(Content::count())->toBe(0);
});

it('creates a draft with no title at the address untitled, then untitled-2 (I-425)', function (): void {
    $user = User::factory()->create()->assignRole('author');
    $create = fn (): Content => app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: '   ',
        document: emptyDocument(),
        fields: [],
        actorId: (string) $user->id,
    ));

    $first = $create();
    $second = $create();

    expect([$first->title, $first->slug, $first->status])->toBe(['', 'untitled', 'draft'])
        ->and($second->slug)->toBe('untitled-2');
});
