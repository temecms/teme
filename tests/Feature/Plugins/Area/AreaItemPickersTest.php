<?php

declare(strict_types=1);

use App\Domain\Contents\Queries\ItemChoices;
use App\Domain\Plugins\Area\AreaForms;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// A picker's rules: a record's items are the posts and pages the person may
// open, and a terms field that stores slugs keeps only slugs a term has now.

/** A declared field, filled with the keys DeclaredArea gives. @return array<string, mixed> */
function pickerField(array $over): array
{
    return array_merge([
        'key' => 'picks', 'type' => 'items', 'label' => 'Only on these articles', 'description' => '', 'required' => false,
        'default' => null, 'options' => [], 'min' => null, 'max' => null, 'maxLength' => null, 'taxonomy' => null, 'storeAs' => null,
        'fields' => [], 'minItems' => null, 'maxItems' => null, 'itemLabel' => '', 'itemTitle' => null, 'visibleWhen' => null, 'scripts' => false,
    ], $over);
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('editor');
    $this->stranger = User::factory()->create();
});

it('accepts a live post the person may open, and keeps its ID', function (): void {
    $live = addrPublished($this->author, 'Feeding roses in spring');

    expect(AreaForms::validated([pickerField([])], ['picks' => [$live->id]], (string) $this->author->id, 'Placard'))
        ->toBe(['picks' => [$live->id]]);
});

it('refuses a draft the person may not update, and writes nothing', function (): void {
    $draft = lifecycleDraft($this->author, 'Not yet');

    expect(fn () => AreaForms::validated([pickerField([])], ['picks' => [$draft->id]], (string) $this->stranger->id, 'Placard'))
        ->toThrow(ValidationException::class);
});

it('refuses an item when no person is saving, and an ID that is no item', function (): void {
    $live = addrPublished($this->author, 'Feeding roses in spring');

    expect(fn () => AreaForms::validated([pickerField([])], ['picks' => [$live->id]], null, 'Placard'))->toThrow(ValidationException::class)
        ->and(fn () => AreaForms::validated([pickerField([])], ['picks' => [(string) Str::ulid()]], (string) $this->author->id, 'Placard'))->toThrow(ValidationException::class);
});

it('accepts a slug a term has now, and refuses one it has not, naming the plugin', function (): void {
    DB::table('terms')->insert(['id' => (string) Str::ulid(), 'taxonomy' => 'tag', 'name' => 'Rivers State budget', 'slug' => 'rivers-state-budget', 'fields' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $field = pickerField(['key' => 'tags', 'type' => 'terms', 'taxonomy' => 'tag', 'storeAs' => 'slug']);

    expect(AreaForms::validated([$field], ['tags' => ['rivers-state-budget']], (string) $this->author->id, 'AdPlacr'))->toBe(['tags' => ['rivers-state-budget']]);

    try {
        AreaForms::validated([$field], ['tags' => ['rivers-state-budget', 'old-slug']], (string) $this->author->id, 'AdPlacr');
        $this->fail('A slug that is not a term must be refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors()['values.tags'][0])->toBe('“old-slug” isn’t a tag on this site now, so AdPlacr can’t save it. Remove it, or choose the tag again.');
    }
});

it('lists only the posts and pages the person may open, six at most, with the total', function (): void {
    foreach (range(1, 8) as $n) {
        addrPublished($this->author, "Roses part {$n}");
    }
    lifecycleDraft($this->author, 'Roses my draft');

    $asAuthor = ItemChoices::search('roses', (string) $this->author->id);
    $asStranger = ItemChoices::search('roses', (string) $this->stranger->id);

    expect(ItemChoices::search('', (string) $this->author->id))->toBe(['shown' => [], 'total' => 0])
        ->and(count($asAuthor['shown']))->toBe(ItemChoices::SHOWN)
        ->and($asAuthor['total'])->toBe(9)
        ->and($asStranger['total'])->toBe(8)
        ->and(array_column($asStranger['shown'], 'title'))->each->not->toContain('Roses my draft');
});

it('never lists or accepts a private published item the person may not edit', function (): void {
    $private = addrPublished($this->author, 'Private rose notes');
    $private->forceFill(['visibility' => 'private'])->save();

    expect(ItemChoices::search('Private rose', (string) $this->stranger->id))->toMatchArray(['shown' => [], 'total' => 0])
        ->and(fn () => AreaForms::validated([pickerField([])], ['picks' => [$private->id]], (string) $this->stranger->id, 'Placard'))->toThrow(ValidationException::class);
});

it('counts every readable match in the query, not only the first candidates', function (): void {
    foreach (range(1, ItemChoices::SHOWN + 2) as $n) {
        addrPublished($this->author, "Rose note {$n}");
    }

    expect(ItemChoices::search('Rose note', (string) $this->stranger->id)['total'])->toBe(ItemChoices::SHOWN + 2)
        ->and(count(ItemChoices::search('Rose note', (string) $this->stranger->id)['shown']))->toBe(ItemChoices::SHOWN);
});

it('keeps an item the record already stores that this person can’t read, and refuses one added now', function (): void {
    $private = addrPublished($this->author, 'Held rose note');
    $private->forceFill(['visibility' => 'private'])->save();
    $field = pickerField([]);

    expect(AreaForms::validated([$field], ['picks' => [$private->id]], (string) $this->stranger->id, 'Placard', ['picks' => [$private->id]]))
        ->toBe(['picks' => [$private->id]])
        ->and(fn () => AreaForms::validated([$field], ['picks' => [$private->id]], (string) $this->stranger->id, 'Placard', ['picks' => []]))->toThrow(ValidationException::class);
});

it('accepts an ID-stored term beyond the first 500, since a terms field searches rather than listing', function (): void {
    $rows = [];
    for ($n = 1; $n <= 501; $n++) {
        $rows[] = ['id' => (string) Str::ulid(), 'taxonomy' => 'tag', 'name' => sprintf('Tag %04d', $n), 'slug' => "tag-{$n}", 'fields' => '{}', 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('terms')->insert($rows);
    $last = DB::table('terms')->where('slug', 'tag-501')->value('id');
    $field = pickerField(['key' => 'tags', 'type' => 'terms', 'taxonomy' => 'tag', 'storeAs' => 'id']);

    expect(AreaForms::validated([$field], ['tags' => [$last]], (string) $this->author->id, 'Placard'))->toBe(['tags' => [$last]]);
});

it('gives a post its categories by name, and a page none', function (): void {
    $post = addrPublished($this->author, 'Roses in the Sport garden');
    $page = addrPublished($this->author, 'Roses on the about page', 'page');
    $category = Str::ulid();
    DB::table('terms')->insert(['id' => (string) $category, 'taxonomy' => 'category', 'name' => 'Sport', 'slug' => 'sport', 'fields' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('content_terms')->insert(['content_id' => $post->id, 'term_id' => (string) $category, 'scope' => 'working', 'position' => 0]);
    DB::table('content_terms')->insert(['content_id' => $page->id, 'term_id' => (string) $category, 'scope' => 'working', 'position' => 0]);

    $shown = collect(ItemChoices::search('roses', (string) $this->author->id)['shown'])->keyBy('title');

    expect($shown['Roses in the Sport garden']['filedUnder'])->toBe(['Sport'])
        ->and($shown['Roses on the about page']['filedUnder'])->toBe([]);
});
