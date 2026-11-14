<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('saves a title and a few lines as a draft of the person\'s own', function (): void {
    $this->actingAs($this->author)
        ->post(route('dashboard.quick-draft'), ['title' => 'Ferry fares idea', 'body' => "First line.\n\nSecond paragraph."])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $content = DB::table('contents')->sole();
    $document = json_decode((string) DB::table('content_revisions')->where('id', $content->working_revision_id)->value('document'), true);

    expect($content->type)->toBe('post')
        ->and($content->status)->toBe('draft')
        ->and($content->title)->toBe('Ferry fares idea')
        ->and($content->author_id)->toBe($this->author->id)
        ->and(array_column($document['content'], 'type'))->toBe(['paragraph', 'paragraph'])
        ->and(array_map(fn (array $node): string => $node['content'][0]['text'], $document['content']))->toBe(['First line.', 'Second paragraph.']);
});

it('refuses a quick draft with no title, and writes nothing', function (): void {
    $this->actingAs($this->author)
        ->post(route('dashboard.quick-draft'), ['title' => '', 'body' => 'An idea.'])
        ->assertSessionHasErrors('title');

    expect(DB::table('contents')->count())->toBe(0);
});

it('writes nothing when the person may not write', function (): void {
    // No role, so not the main type's create ability: CreateDraft refuses.
    $reader = User::factory()->create();

    $this->actingAs($reader)
        ->post(route('dashboard.quick-draft'), ['title' => 'Ferry fares idea'])
        ->assertForbidden();

    expect(DB::table('contents')->count())->toBe(0)
        ->and(DB::table('content_revisions')->count())->toBe(0);
});

it('stores the lines as a document, never as HTML', function (): void {
    $this->actingAs($this->author)
        ->post(route('dashboard.quick-draft'), ['title' => 'Markup', 'body' => '<b>x</b>'])
        ->assertSessionHasNoErrors();

    $document = json_decode((string) DB::table('content_revisions')
        ->where('id', DB::table('contents')->value('working_revision_id'))
        ->value('document'), true);

    // Rule 7: the markup is text inside a paragraph, with no bold mark.
    expect($document['content'])->toHaveCount(1)
        ->and($document['content'][0]['type'])->toBe('paragraph')
        ->and($document['content'][0]['content'][0]['text'])->toBe('<b>x</b>')
        ->and($document['content'][0]['content'][0])->not->toHaveKey('marks');
});
