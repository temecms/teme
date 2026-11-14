<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->mine = cmItem($this->author, 'Mine');
    $this->theirs = cmItem($this->editor, 'Theirs');
    $this->discussionOf = fn (Content $item): string => (string) DB::table('contents')->where('id', $item->id)->value('comment_status');
    // Quick edit as its form sends it, against the item's revision now.
    $this->quickEdit = fn (User $person, Content $item, array $fields) => $this->actingAs($person)
        ->from(route('content.index', ['type' => 'posts']))
        ->patch(route('content.details', ['type' => 'posts', 'content' => $item->id]), [
            'expected_revision_id' => DB::table('contents')->where('id', $item->id)->value('working_revision_id'),
            'title' => $item->title,
            ...$fields,
        ]);
});

it('gives each row its Discussion, and whether this person may change it (I-688)', function (): void {
    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows', fn ($rows): bool => collect($rows)
            ->mapWithKeys(fn (array $row): array => [$row['title'] => $row['discussion']])
            ->sortKeys()
            ->all() === ['Mine' => ['value' => 'open', 'canSet' => true], 'Theirs' => ['value' => 'open', 'canSet' => false]]));
});

it('gives the rows no Discussion while comments are off (I-484)', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.discussion', null)->where('rows.1.discussion', null));
});

it('sets Discussion from Quick edit (I-688)', function (): void {
    ($this->quickEdit)($this->author, $this->mine, ['discussion' => 'hidden'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(($this->discussionOf)($this->mine))->toBe('hidden');
});

it('leaves Discussion as it is when Quick edit doesn\'t send it, so a form opened earlier can\'t undo someone\'s change', function (): void {
    ($this->quickEdit)($this->editor, $this->mine, ['discussion' => 'closed'])->assertSessionHasNoErrors();
    ($this->quickEdit)($this->author, $this->mine, ['title' => 'Mine, retitled'])->assertSessionHasNoErrors();

    expect(($this->discussionOf)($this->mine))->toBe('closed');
});

it('saves nothing from Quick edit when its Discussion is refused, so the same form can be sent again', function (): void {
    cmSettings(['comments' => 'off'], 'modules');
    $revision = DB::table('contents')->where('id', $this->mine->id)->value('working_revision_id');

    ($this->quickEdit)($this->author, $this->mine, ['title' => 'Mine, retitled', 'discussion' => 'closed'])
        ->assertSessionHasErrors(['discussion' => 'Comments are switched off.']);

    expect(($this->discussionOf)($this->mine))->toBe('open')
        ->and(DB::table('contents')->where('id', $this->mine->id)->value('working_revision_id'))->toBe($revision);
});
