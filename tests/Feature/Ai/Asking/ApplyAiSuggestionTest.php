<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\Asking\TextBlocks;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday.');
    $this->fake = aiReady();

    // Asks about "rise on" in the second paragraph (offsets 6 to 13), and AI answers "go up".
    $this->ask = function (): string {
        $this->fake->answer(aiText('go up'));

        return aiAsk($this->author, $this->item, '1', ['from' => 6, 'to' => 13])->assertStatus(202)->json('id');
    };

    // Uses it as the editor does: the block as it would be, at its path now, on the revision the editor's work is based on.
    $this->use = fn (string $id, string $text = 'Fares go up Monday.', ?string $path = '1', ?string $revisionId = null, ?User $as = null, array $attrs = []): TestResponse => $this
        ->actingAs($as ?? $this->author)
        ->postJson(route('ai.requests.apply', $id), [
            'revision_id' => $revisionId ?? $this->item->refresh()->working_revision_id,
            'path' => $path,
            'block' => $path === null ? null : ['type' => 'paragraph', ...($attrs === [] ? [] : ['attrs' => $attrs]), 'content' => [['type' => 'text', 'text' => $text]]],
        ]);

    // The text of the paragraph at $path in a revision.
    $this->paragraph = fn (string $revisionId, string $path): string => TextBlocks::text(TextBlocks::at((array) ContentRevision::query()->findOrFail($revisionId)->document, $path) ?? []);

    // An image of the author's with every detail set, and AI asked for its alt text.
    $this->image = function (): array {
        Storage::fake('media');
        Storage::fake('media-originals');
        aiReady('fixture-alt', AiSubjectType::Media)->answer(aiText('A ferry at the quay.'));
        $image = mediaUpload($this->author, mediaJpeg());
        app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($image->id, (string) $this->author->id, 'Quay', 'Boats.', 'At dawn.', 'Ada'));
        $id = $this->actingAs($this->author)
            ->postJson(route('ai.requests.store'), ['task' => 'fixture-alt', 'subject_type' => 'media', 'subject_id' => $image->id, 'target' => 'alt'])
            ->assertStatus(202)->json('id');

        return [$image, $id];
    };
});

it('uses a suggestion as a revision marked as the assistant\'s, attributed to the person, and settles it (I-317)', function (): void {
    $id = ($this->ask)();
    $before = $this->item->working_revision_id;

    $revisionId = ($this->use)($id)->assertOk()->json('revisionId');
    $revision = ContentRevision::query()->findOrFail($revisionId);

    expect($revisionId)->not->toBe($before)
        ->and($this->item->refresh()->working_revision_id)->toBe($revisionId)
        ->and($revision->only(['kind', 'origin']))->toBe(['kind' => 'ai', 'origin' => 'ai'])
        ->and((string) $revision->author_id)->toBe((string) $this->author->id)
        ->and(($this->paragraph)($revisionId, '1'))->toBe('Fares go up Monday.')
        ->and(($this->paragraph)($revisionId, '0'))->toBe('The ferry is late.')
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Applied, 'revision_id' => $revisionId]);

    aiCollect($this->author, $id)->assertNotFound();
});

it('writes the block as the editor made it, keeping the paragraph\'s other marks', function (): void {
    $marked = ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Fares rise on '],
        ['type' => 'text', 'text' => 'Monday', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => '.'],
    ]];
    docSave($this->item, $this->author, docWith(docText('The ferry is late.'), $marked));
    $id = ($this->ask)();
    $used = ['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Fares go up '],
        ['type' => 'text', 'text' => 'Monday', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => '.'],
    ]];

    $revisionId = $this->actingAs($this->author)
        ->postJson(route('ai.requests.apply', $id), ['revision_id' => $this->item->refresh()->working_revision_id, 'path' => '1', 'block' => $used])
        ->assertOk()->json('revisionId');

    // toEqual: JSONB keeps an object's keys in its own order, not the order written.
    expect(TextBlocks::at((array) ContentRevision::query()->findOrFail($revisionId)->document, '1'))->toEqual($used);
});

it('uses a suggestion on a paragraph that moved since the asking, at its new place', function (): void {
    $id = ($this->ask)();
    docSave($this->item, $this->author, docWith(docText('A new first line.'), docText('The ferry is late.'), docText('Fares rise on Monday.')));

    $revisionId = ($this->use)($id, path: '2')->assertOk()->json('revisionId');

    expect(($this->paragraph)($revisionId, '2'))->toBe('Fares go up Monday.')
        ->and(($this->paragraph)($revisionId, '0'))->toBe('A new first line.');
});

it('refuses a paragraph that changed or went after the asking, settling it as out of date and writing nothing', function (string $case): void {
    $id = ($this->ask)();
    docSave($this->item, $this->author, docWith(docText('The ferry is late.'), docText('Fares rise on Tuesday.')));
    $working = $this->item->refresh()->working_revision_id;

    ($this->use)($id, path: $case === 'gone from the editor' ? null : '1')
        ->assertStatus(409)
        ->assertExactJson(['outOfDate' => true, 'message' => 'This paragraph changed after you asked. Ask again.']);

    expect($this->item->refresh()->working_revision_id)->toBe($working)
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Stale);
    aiCollect($this->author, $id)->assertNotFound();
})->with(['changed', 'gone from the editor']);

it('refuses a block that isn\'t exactly the suggestion in the place asked about, writing nothing', function (string $case): void {
    $id = ($this->ask)();
    $working = $this->item->working_revision_id;

    $response = match ($case) {
        'other words too' => ($this->use)($id, 'Fares go up next Monday.'),
        'a new alignment' => ($this->use)($id, attrs: ['textAlign' => 'center']),
        default => $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id), [
            'revision_id' => $working,
            'path' => '1',
            'block' => ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fares go up Monday.']]],
        ]),
    };

    $response->assertStatus(422)->assertExactJson(['message' => 'This change isn\'t the suggestion in the place it was asked about.']);

    expect($this->item->refresh()->working_revision_id)->toBe($working)
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
})->with(['other words too', 'a new alignment', 'a heading for a paragraph']);

it('uses a suggestion on a block the editor gave an id its stored copy lacks, and stores that id (I-3362)', function (): void {
    $id = ($this->ask)();
    $nodeId = '01K5Z9P2QH7N3X4C8V6B0M1T3D';

    $revisionId = ($this->use)($id, attrs: ['id' => $nodeId])->assertOk()->json('revisionId');
    $block = TextBlocks::at((array) ContentRevision::query()->findOrFail($revisionId)->document, '1');

    expect($block['attrs']['id'] ?? null)->toBe($nodeId)
        ->and(TextBlocks::text($block ?? []))->toBe('Fares go up Monday.');
});

it('refuses a block whose id isn\'t the one stored, writing nothing (I-3362)', function (string $case): void {
    $stored = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    docSave($this->item, $this->author, docWith(
        docText('The ferry is late.'),
        ['type' => 'paragraph', 'attrs' => ['id' => $stored], 'content' => [['type' => 'text', 'text' => 'Fares rise on Monday.']]],
    ));
    $id = ($this->ask)();
    $working = $this->item->refresh()->working_revision_id;

    ($this->use)($id, attrs: $case === 'another id' ? ['id' => '01K5Z9P2QH7N3X4C8V6B0M1T3E'] : ['textAlign' => null])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'This change isn\'t the suggestion in the place it was asked about.']);

    expect($this->item->refresh()->working_revision_id)->toBe($working)
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
})->with(['another id', 'no id']);

it('answers as a save from an old revision does when someone else saved since, so the editor\'s stale dialog opens', function (): void {
    $id = ($this->ask)();
    $old = $this->item->working_revision_id;
    $editor = User::factory()->create()->assignRole('editor');
    docSave($this->item, $editor, docWith(docText('The ferry is late.'), docText('Fares rise on Monday.'), docText('An editor’s line.')));

    ($this->use)($id, revisionId: $old)->assertStatus(409)->assertJson(['stale' => true]);

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
});

it('says a suggestion left for more than 15 minutes has expired, settling it as out of date', function (): void {
    $id = ($this->ask)();
    $this->travel(16)->minutes();

    ($this->use)($id)->assertStatus(409)->assertExactJson(['outOfDate' => true, 'message' => 'This suggestion has expired. Ask again.']);

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Stale);
});

it('lets only the person who asked use a suggestion, while they hold ai.use, writing nothing', function (string $case): void {
    $id = ($this->ask)();
    $working = $this->item->working_revision_id;
    $person = $case === 'an editor who didn\'t ask'
        ? User::factory()->create()->assignRole('editor')
        : $this->author->syncRoles(['contributor']);

    ($this->use)($id, as: $person)->assertForbidden();

    expect($this->item->refresh()->working_revision_id)->toBe($working)
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
})->with(['an editor who didn\'t ask', 'the asker, no longer holding ai.use']);

it('refuses a second Use of one suggestion', function (): void {
    $id = ($this->ask)();
    ($this->use)($id)->assertOk();

    ($this->use)($id)->assertStatus(409)->assertExactJson(['message' => 'This suggestion was already used or put aside.']);
});

it('leaves an ordinary revision, which Restore takes back (I-317)', function (): void {
    $id = ($this->ask)();
    $before = $this->item->working_revision_id;
    ($this->use)($id)->assertOk();

    $this->actingAs($this->author)->post(route('content.revisions.restore', [$this->item->id, $before]))->assertRedirect();

    expect(($this->paragraph)((string) $this->item->refresh()->working_revision_id, '1'))->toBe('Fares rise on Monday.');
});

it('uses a suggested alt text through the image\'s details, keeping the rest of them (I-319)', function (): void {
    [$image, $id] = ($this->image)();

    $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id))->assertOk()->assertExactJson(['revisionId' => null]);

    expect(Media::withTrashed()->findOrFail($image->id)->only(['title', 'alt', 'caption', 'credit']))
        ->toBe(['title' => 'Quay', 'alt' => 'A ferry at the quay.', 'caption' => 'At dawn.', 'credit' => 'Ada'])
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Applied, 'revision_id' => null]);
});

it('refuses a suggested alt text when the image\'s alt text changed after the asking', function (): void {
    [$image, $id] = ($this->image)();
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($image->id, (string) $this->author->id, 'Quay', 'Two boats.', 'At dawn.', 'Ada'));

    $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id))
        ->assertStatus(409)
        ->assertExactJson(['outOfDate' => true, 'message' => 'This image’s alt text changed after you asked. Ask again.']);

    expect(Media::withTrashed()->findOrFail($image->id)->alt)->toBe('Two boats.')
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Stale);
});
