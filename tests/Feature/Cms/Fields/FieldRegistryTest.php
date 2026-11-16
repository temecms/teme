<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\TextField;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

function fieldsDraft(User $author, array $fields): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput('post', 'With fields', ['type' => 'doc', 'content' => []], $fields, (string) $author->id));
}

it('registers the five built-in fields for posts and pages', function (): void {
    $handles = fn (string $type): array => array_map(fn (Field $field): string => $field->handle, app(FieldRegistry::class)->forType($type));

    expect($handles('post'))->toBe(['seo_title', 'meta_description', 'excerpt', 'canonical', 'noindex', 'featured_image', 'template', 'sticky', 'social_title', 'social_description', 'social_image', 'nosnippet', 'max_snippet', 'noarchive', 'primary_category', 'page_type', 'article_type', 'insertions_off'])
        ->and($handles('page'))->toBe(['seo_title', 'meta_description', 'excerpt', 'canonical', 'noindex', 'featured_image', 'template', 'social_title', 'social_description', 'social_image', 'nosnippet', 'max_snippet', 'noarchive', 'primary_category', 'page_type', 'article_type', 'insertions_off']);
});

it('stores fields under their key, trimmed and typed', function (): void {
    $content = fieldsDraft($this->author, [
        BuiltInFields::SEO_TITLE => '  A better title  ',
        BuiltInFields::NOINDEX => '1',
    ]);

    // toEqual, not toBe: JSONB does not keep an object's keys in the order
    // they were written.
    expect($content->workingRevision->fields)->toEqual([
        BuiltInFields::SEO_TITLE => 'A better title',
        BuiltInFields::NOINDEX => true,
    ]);
});

it('refuses keys no field declares, handles included', function (): void {
    expect(fn () => fieldsDraft($this->author, ['seo_title' => 'By handle']))->toThrow(ValidationException::class)
        ->and(fn () => fieldsDraft($this->author, ['01K5Z0F1ED00000000000000X9' => 'Unknown']))->toThrow(ValidationException::class);
});

it('checks each value against its type', function (string $key, mixed $value): void {
    expect(fn () => fieldsDraft($this->author, [$key => $value]))->toThrow(ValidationException::class);
})->with([
    'SEO title too long' => [BuiltInFields::SEO_TITLE, str_repeat('a', 201)],
    'meta description too long' => [BuiltInFields::META_DESCRIPTION, str_repeat('a', 321)],
    'canonical not a choice' => [BuiltInFields::CANONICAL, 'not a url'],
    'noindex not a boolean' => [BuiltInFields::NOINDEX, 'perhaps'],
]);

it('refuses a field that does not document itself, and a duplicate key or handle', function (): void {
    $registry = new FieldRegistry;
    $make = fn (string $key, string $handle, string $description = 'Described.'): Field => new Field($key, $handle, 'Label', $description, new TextField, ['post'], '0.1.0');

    $registry->register($make('01K5Z0F1ED0000000000000TA1', 'one'));

    expect(fn () => $registry->register($make('01K5Z0F1ED0000000000000TA2', 'two', ' ')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register($make('01K5Z0F1ED0000000000000TA1', 'three')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register($make('not-a-ulid', 'four')))->toThrow(InvalidArgumentException::class);

    // Since 1.5·0 a handle is unique per type, checked once every type is
    // registered: a second 'one' on posts is refused by verify().
    $registry->register($make('01K5Z0F1ED0000000000000TA3', 'one'));

    expect(fn () => $registry->verify(app(ContentTypeRegistry::class)))->toThrow(InvalidArgumentException::class);
});
