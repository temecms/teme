<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\MediaField;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->stored = fn (array $fields): array => (array) refsDraft($this->author, $fields)->workingRevision->fields;
});

it('gives every type with the seo feature the social fields, the snippet controls, primary category, and the canonical under its old key (I-292, I-293, I-714)', function (): void {
    $registry = app(FieldRegistry::class);
    $canonical = $registry->find(BuiltInFields::CANONICAL);

    expect(array_map(fn (Field $field): string => $field->handle, $registry->forType('post')))
        ->toBe(['seo_title', 'meta_description', 'excerpt', 'canonical', 'noindex', 'featured_image', 'template', 'sticky', 'social_title', 'social_description', 'social_image', 'nosnippet', 'max_snippet', 'noarchive', 'primary_category', 'page_type', 'article_type'])
        ->and(BuiltInFields::CANONICAL)->toBe('01K5Z0F1ED00000000000000C4')
        ->and($canonical?->label)->toBe('Canonical address')
        ->and($canonical?->type->name())->toBe('canonical')
        ->and($registry->find(BuiltInFields::PRIMARY_CATEGORY)?->type->name())->toBe('term')
        ->and($registry->find(BuiltInFields::SOCIAL_TITLE)?->maxLength)->toBe(200)
        ->and($registry->find(BuiltInFields::SOCIAL_DESCRIPTION)?->maxLength)->toBe(320)
        ->and($registry->find(BuiltInFields::SOCIAL_IMAGE)?->type)->toBeInstanceOf(MediaField::class)
        ->and($registry->find(BuiltInFields::SOCIAL_IMAGE)?->type->allowsDecorative)->toBeFalse();
});

it('stores a canonical choice as nothing for the item itself, another item by its ID, or another site\'s address, and reads the old plain address as one (I-292)', function (): void {
    $other = Content::query()->findOrFail(refsDraft($this->author, [])->id);

    expect(($this->stored)([BuiltInFields::CANONICAL => null])[BuiltInFields::CANONICAL])->toBeNull()
        ->and(($this->stored)([BuiltInFields::CANONICAL => ''])[BuiltInFields::CANONICAL])->toBeNull()
        ->and(($this->stored)([BuiltInFields::CANONICAL => []])[BuiltInFields::CANONICAL])->toBeNull()
        ->and(($this->stored)([BuiltInFields::CANONICAL => ['item' => $other->id]])[BuiltInFields::CANONICAL])->toBe(['item' => $other->id])
        ->and(($this->stored)([BuiltInFields::CANONICAL => ['address' => ' https://ledger.example/first/ ']])[BuiltInFields::CANONICAL])->toBe(['address' => 'https://ledger.example/first/'])
        ->and(($this->stored)([BuiltInFields::CANONICAL => 'https://ledger.example/first/'])[BuiltInFields::CANONICAL])->toBe(['address' => 'https://ledger.example/first/']);
});

it('refuses a canonical choice that isn\'t one, and an address on this site, saying what to do (rule 9)', function (string $case, string $message): void {
    $trashed = refsDraft($this->author, []);
    $trashed->delete();

    $value = match ($case) {
        'both' => ['item' => $trashed->id, 'address' => 'https://ledger.example/'],
        'something else' => ['url' => 'https://ledger.example/'],
        'a deleted item' => ['item' => $trashed->id],
        'no such item' => ['item' => '01jx0000000000000000000000'],
        'a script' => ['address' => 'javascript:alert(1)'],
        'half an address' => ['address' => 'ledger.example/first/'],
        'a quote in it' => ['address' => 'https://ledger.example/"><script>'],
        default => ['address' => 'https://LOCALHOST/harbour-news/'],
    };

    expect(fn () => ($this->stored)([BuiltInFields::CANONICAL => $value]))->toThrow(ValidationException::class, $message);
})->with([
    'both' => ['both', 'Choose this item, another item, or an address on another site.'],
    'something else' => ['something else', 'Choose this item, another item, or an address on another site.'],
    'a deleted item' => ['a deleted item', 'Choose an item that hasn’t been deleted.'],
    'no such item' => ['no such item', 'Choose an item that hasn’t been deleted.'],
    'a script' => ['a script', 'Enter the whole address, starting https://.'],
    'half an address' => ['half an address', 'Enter the whole address, starting https://.'],
    'a quote in it' => ['a quote in it', 'Enter the whole address, starting https://.'],
    'this site' => ['this site', 'That address is on this site. Choose its item instead, so the link survives a move.'],
]);

it('stores a primary category as its term\'s ID, and refuses a tag, an unknown term and anything else (I-295)', function (string $case): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $tides = typesTerm($this->editor, 'tag', 'Tides');

    if ($case === 'a category') {
        expect(($this->stored)([BuiltInFields::PRIMARY_CATEGORY => $news->id])[BuiltInFields::PRIMARY_CATEGORY])->toBe($news->id)
            ->and(($this->stored)([BuiltInFields::PRIMARY_CATEGORY => ''])[BuiltInFields::PRIMARY_CATEGORY])->toBeNull();

        return;
    }

    $value = match ($case) {
        'a tag' => $tides->id,
        'an unknown term' => '01jx0000000000000000000000',
        default => ['id' => $news->id],
    };

    expect(fn () => ($this->stored)([BuiltInFields::PRIMARY_CATEGORY => $value]))->toThrow(ValidationException::class, 'Choose a category this site has.');
})->with(['a category', 'a tag', 'an unknown term', 'an array']);

it('stores the snippet controls and the social text as typed, trimmed, and refuses social text past its limit (I-714, I-882)', function (): void {
    $stored = ($this->stored)([
        BuiltInFields::NOSNIPPET => '1',
        BuiltInFields::MAX_SNIPPET => '160',
        BuiltInFields::NOARCHIVE => true,
        BuiltInFields::SOCIAL_TITLE => '  Ferry fares rise  ',
        BuiltInFields::SOCIAL_DESCRIPTION => ' What the new fares mean for commuters. ',
    ]);

    expect($stored)->toMatchArray([
        BuiltInFields::NOSNIPPET => true,
        BuiltInFields::MAX_SNIPPET => 160,
        BuiltInFields::NOARCHIVE => true,
        BuiltInFields::SOCIAL_TITLE => 'Ferry fares rise',
        BuiltInFields::SOCIAL_DESCRIPTION => 'What the new fares mean for commuters.',
    ])
        ->and(fn () => ($this->stored)([BuiltInFields::SOCIAL_TITLE => str_repeat('a', 201)]))->toThrow(ValidationException::class)
        ->and(fn () => ($this->stored)([BuiltInFields::SOCIAL_DESCRIPTION => str_repeat('a', 321)]))->toThrow(ValidationException::class);
});
