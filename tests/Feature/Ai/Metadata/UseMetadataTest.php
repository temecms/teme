<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Queries\AssistantRevisions;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = docSave(
        lifecycleDraft($this->author, 'Ferry fares rise'),
        $this->author,
        docWith(docText('Fares rise by 15% from 1 October.')),
        [BuiltInFields::SEO_TITLE => 'Fares', BuiltInFields::EXCERPT => 'Dearer crossings.'],
    )->refresh();
    $this->fake = mdReady();

    // Asks for metadata, and AI answers with mdValues().
    $this->ask = function (): string {
        $this->fake->answer(mdAnswer());

        return mdAsk($this->author, $this->item)->assertStatus(202)->json('id');
    };

    // Uses these proposals as the editor does, on the revision its work is based on.
    $this->use = fn (string $id, array $fields, ?string $revisionId = null, ?User $as = null): TestResponse => $this
        ->actingAs($as ?? $this->author)
        ->postJson(route('ai.requests.apply', $id), [
            'revision_id' => $revisionId ?? $this->item->refresh()->working_revision_id,
            'fields' => $fields,
        ]);

    // A field's value in a revision, by the field's key.
    $this->field = fn (string $revisionId, string $key): mixed => ((array) ContentRevision::query()->findOrFail($revisionId)->fields)[$key] ?? null;

    // The person saves these fields themselves; the new working revision's ID.
    $this->save = fn (array $fields): string => (string) docSave($this->item, $this->author, docWith(docText('Fares rise by 15% from 1 October.')), $fields)->working_revision_id;
});

it('uses one proposal as a revision marked as the assistant\'s, changing only its field, and settles the request (I-1313, I-317)', function (): void {
    $id = ($this->ask)();
    $before = $this->item->working_revision_id;

    $revisionId = ($this->use)($id, ['seo_title'])->assertOk()->json('revisionId');
    $revision = ContentRevision::query()->findOrFail($revisionId);

    expect($revisionId)->not->toBe($before)
        ->and($this->item->refresh()->working_revision_id)->toBe($revisionId)
        ->and($revision->only(['kind', 'origin']))->toBe(['kind' => 'ai', 'origin' => 'ai'])
        ->and((string) $revision->author_id)->toBe((string) $this->author->id)
        ->and(($this->field)($revisionId, BuiltInFields::SEO_TITLE))->toBe(mdValues()['seo_title'])
        ->and(($this->field)($revisionId, BuiltInFields::EXCERPT))->toBe('Dearer crossings.')
        ->and(($this->field)($revisionId, BuiltInFields::META_DESCRIPTION))->toBeNull()
        ->and($revision->document)->toEqual(ContentRevision::query()->findOrFail($before)->document)
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Applied, 'revision_id' => $revisionId]);
});

it('keeps the other proposals usable after the first, each Use its own revision, the request still naming the first (I-1313)', function (): void {
    $id = ($this->ask)();
    $first = ($this->use)($id, ['seo_title'])->assertOk()->json('revisionId');

    aiCollect($this->author, $id)->assertOk()->assertJsonPath('values', array_slice(mdValues(), 1));

    $second = ($this->use)($id, ['meta_description', 'excerpt'])->assertOk()->json('revisionId');

    expect($second)->not->toBe($first)
        ->and(ContentRevision::query()->findOrFail($second)->only(['kind', 'origin']))->toBe(['kind' => 'ai', 'origin' => 'ai'])
        ->and(($this->field)($second, BuiltInFields::SEO_TITLE))->toBe(mdValues()['seo_title'])
        ->and(($this->field)($second, BuiltInFields::META_DESCRIPTION))->toBe(mdValues()['meta_description'])
        ->and(($this->field)($second, BuiltInFields::EXCERPT))->toBe(mdValues()['excerpt'])
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Applied, 'revision_id' => $first]);

    aiCollect($this->author, $id)->assertOk()->assertJsonPath('values', array_slice(mdValues(), 3));
});

it('uses all five as one revision, then forgets the suggestion (I-1313)', function (): void {
    $id = ($this->ask)();
    $count = ContentRevision::query()->where('content_id', $this->item->id)->count();

    $revisionId = ($this->use)($id, array_keys(mdValues()))->assertOk()->json('revisionId');

    expect(ContentRevision::query()->where('content_id', $this->item->id)->count())->toBe($count + 1)
        ->and(($this->field)($revisionId, BuiltInFields::SEO_TITLE))->toBe(mdValues()['seo_title'])
        ->and(($this->field)($revisionId, BuiltInFields::META_DESCRIPTION))->toBe(mdValues()['meta_description'])
        ->and(($this->field)($revisionId, BuiltInFields::EXCERPT))->toBe(mdValues()['excerpt'])
        ->and(($this->field)($revisionId, BuiltInFields::SOCIAL_TITLE))->toBe(mdValues()['social_title'])
        ->and(($this->field)($revisionId, BuiltInFields::SOCIAL_DESCRIPTION))->toBe(mdValues()['social_description']);

    aiCollect($this->author, $id)->assertNotFound();
});

it('writes nothing when the proposals arrive: what the person wrote stays until they choose Use (I-1313)', function (): void {
    $before = $this->item->working_revision_id;
    $count = ContentRevision::query()->where('content_id', $this->item->id)->count();

    ($this->ask)();

    expect($this->item->refresh()->working_revision_id)->toBe($before)
        ->and(ContentRevision::query()->where('content_id', $this->item->id)->count())->toBe($count)
        ->and(($this->field)($before, BuiltInFields::SEO_TITLE))->toBe('Fares');
});

it('refuses a proposal whose field changed after the asking, writing and settling nothing, and keeps the others usable (I-317)', function (): void {
    $id = ($this->ask)();
    $saved = ($this->save)([BuiltInFields::SEO_TITLE => 'My own title', BuiltInFields::EXCERPT => 'Dearer crossings.']);

    ($this->use)($id, ['seo_title'])->assertStatus(409)->assertExactJson(['outOfDate' => true, 'message' => 'This field changed after you asked. Ask again.']);

    expect($this->item->refresh()->working_revision_id)->toBe($saved)
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);

    ($this->use)($id, ['meta_description'])->assertOk();
});

it('refuses a Use of several when any of them changed after the asking, writing none of them (I-317)', function (): void {
    $id = ($this->ask)();
    $saved = ($this->save)([BuiltInFields::SEO_TITLE => 'Fares', BuiltInFields::EXCERPT => 'My own excerpt.']);

    ($this->use)($id, array_keys(mdValues()))->assertStatus(409)->assertExactJson([
        'outOfDate' => true,
        'message' => 'Some of these fields changed after you asked. Use the others one at a time, or ask again.',
    ]);

    expect($this->item->refresh()->working_revision_id)->toBe($saved);
});

it('says a proposal was already used when it is used again', function (): void {
    $id = ($this->ask)();
    ($this->use)($id, ['seo_title'])->assertOk();
    $after = $this->item->refresh()->working_revision_id;

    ($this->use)($id, ['seo_title'])->assertStatus(409)->assertExactJson(['message' => 'This suggestion was already used or put aside.']);

    expect($this->item->refresh()->working_revision_id)->toBe($after);
});

it('answers a save made elsewhere as the editor\'s stale save, writing nothing', function (): void {
    $id = ($this->ask)();
    $based = $this->item->refresh()->working_revision_id;
    docSave($this->item, $this->author, docWith(docText('Saved in another window.')));

    ($this->use)($id, ['seo_title'], $based)->assertStatus(409)->assertJson(['stale' => true]);

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
});

it('writes nothing when someone other than the asker uses it, even one who may edit the item', function (): void {
    $id = ($this->ask)();
    $before = $this->item->working_revision_id;
    $editor = User::factory()->create()->assignRole('editor');

    ($this->use)($id, ['seo_title'], null, $editor)->assertForbidden();

    expect($this->item->refresh()->working_revision_id)->toBe($before)
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
});

it('settles a first Use after the suggestion expired as out of date, and finds a later one settled', function (): void {
    $id = ($this->ask)();
    $this->travel(16)->minutes();

    ($this->use)($id, ['seo_title'])->assertStatus(409)->assertExactJson(['outOfDate' => true, 'message' => 'This suggestion has expired. Ask again.']);

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Stale);

    $again = ($this->ask)();
    ($this->use)($again, ['seo_title'])->assertOk();
    $this->travel(16)->minutes();

    ($this->use)($again, ['excerpt'])->assertStatus(409)->assertExactJson(['message' => 'This suggestion was already used or put aside.']);
});

it('puts the rest aside after a partial Use, leaving the request recorded as used (I-318)', function (): void {
    $id = ($this->ask)();
    $revisionId = ($this->use)($id, ['seo_title'])->assertOk()->json('revisionId');

    $this->actingAs($this->author)->deleteJson(route('ai.requests.destroy', $id))->assertNoContent();

    expect(AiActivity::query()->findOrFail($id)->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Applied, 'revision_id' => $revisionId]);

    aiCollect($this->author, $id)->assertNotFound();
});

it('refuses fields for a suggestion about a paragraph, writing nothing', function (): void {
    aiReady();
    $this->fake->answer(aiText('Fares go up by 15% from 1 October.'));
    $id = aiAsk($this->author, $this->item, '0')->assertStatus(202)->json('id');

    ($this->use)($id, ['seo_title'])->assertStatus(422)->assertExactJson(['message' => 'This suggestion has no fields to use.']);

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
});

it('names the first revision it wrote "Metadata" on the History pane, and a later one only "Assistant" (I-1307)', function (): void {
    $id = ($this->ask)();
    $first = ($this->use)($id, ['seo_title'])->assertOk()->json('revisionId');
    $second = ($this->use)($id, ['excerpt'])->assertOk()->json('revisionId');

    expect(app(AssistantRevisions::class)->among([$first, $second], false))->toBe([
        $first => ['label' => 'Metadata', 'row' => null],
        $second => ['label' => null, 'row' => null],
    ]);
});

it('refuses a Use that names no field, an empty list or a field twice, as a developer\'s mistake', function (string $case): void {
    $id = ($this->ask)();
    $fields = ['an empty list' => [], 'a field twice' => ['seo_title', 'seo_title']][$case] ?? null;
    $body = ['revision_id' => $this->item->refresh()->working_revision_id] + ($fields === null ? [] : ['fields' => $fields]);

    $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id), $body)->assertStatus(422)->assertExactJson([
        'message' => $case === 'no field' ? 'This suggestion has no target Teme can use.' : 'A field suggestion is used by naming each of its fields once.',
    ]);

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
})->with(['no field', 'an empty list', 'a field twice']);
