<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ManageApi\ManageApiFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    $this->editor = User::factory()->create(['name' => 'Eda Editor'])->assignRole('editor');
    ManageApiFixture::on();
});

/**
 * A Tiptap body of one paragraph.
 *
 * @return array<string, mixed>
 */
function apiBody(string $text = 'Hello from a tool.'): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

/**
 * The counts of what an item's create writes.
 *
 * @return array{contents: int, revisions: int, scheduled: int}
 */
function apiWritten(): array
{
    return [
        'contents' => DB::table('contents')->count(),
        'revisions' => DB::table('content_revisions')->count(),
        'scheduled' => DB::table('scheduled_publications')->count(),
    ];
}

it('creates a draft with its title, body, terms, featured media, author and a field, as the key’s person through the key', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write'], name: 'Newsroom tool');
    $author = User::factory()->create(['name' => 'Ada Writer'])->assignRole('author');
    $news = taxonomyTerm('category', 'News');
    $tag = taxonomyTerm('tag', 'Harbour');
    $media = mediaUpload($this->editor, mediaJpeg(), 'Harbour.jpg');

    $response = $this->postJson('/api/manage/v1/items', [
        'type' => 'post',
        'title' => 'Boats come home',
        'body' => apiBody(),
        'slug' => 'boats-home',
        'terms' => ['category' => [$news->id], 'tag' => [$tag->id]],
        'featured_media' => $media->id,
        'author' => $author->id,
        'fields' => ['meta_description' => 'The fleet returns.'],
        'status' => 'draft',
    ], ManageApiFixture::headers($secret));

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'post')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.slug', 'boats-home')
        ->assertJsonPath('data.title', 'Boats come home')
        ->assertJsonPath('data.url', null);

    $content = DB::table('contents')->where('id', $response->json('data.id'))->first();
    $revision = DB::table('content_revisions')->where('id', $content->working_revision_id)->first();
    $fields = json_decode((string) $revision->fields, true);

    expect((int) $content->author_id)->toBe($author->id)
        ->and($content->slug)->toBe('boats-home')
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->pluck('via')->unique()->all())->toBe(['api:Newsroom tool'])
        ->and($fields[BuiltInFields::META_DESCRIPTION])->toBe('The fleet returns.')
        ->and($fields[BuiltInFields::FEATURED_IMAGE]['id'])->toBe($media->id)
        ->and(json_decode((string) $revision->terms, true))->toEqualCanonicalizing([$news->id, $tag->id])
        ->and(json_decode((string) $revision->document, true)['content'][0]['content'][0]['text'])->toBe('Hello from a tool.');
});

it('refuses published without content.publish before anything is written', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write']);

    $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'Now', 'body' => apiBody(), 'status' => 'published'], ManageApiFixture::headers($secret))
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');

    expect(apiWritten())->toBe(['contents' => 0, 'revisions' => 0, 'scheduled' => 0]);
});

it('publishes an item when asked, with its public address', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write', 'content.publish']);

    $response = $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'Out now', 'body' => apiBody(), 'status' => 'published'], ManageApiFixture::headers($secret));

    $response->assertStatus(201)->assertJsonPath('data.status', 'published');

    expect($response->json('data.url'))->toStartWith(rtrim(url('/'), '/').'/')
        ->and($response->json('data.url'))->toContain('out-now')
        ->and(DB::table('contents')->value('status'))->toBe('published')
        ->and(DB::table('content_revisions')->whereNotNull('via')->count())->toBe(DB::table('content_revisions')->count());
});

it('schedules an item when asked', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write', 'content.publish']);
    $when = now()->addDay()->startOfMinute()->toIso8601String();

    $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'Tomorrow', 'body' => apiBody(), 'status' => 'scheduled', 'publish_at' => $when], ManageApiFixture::headers($secret))
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'scheduled');

    expect(DB::table('scheduled_publications')->count())->toBe(1)
        ->and(DB::table('contents')->value('status'))->not->toBe('published');
});

it('keeps the draft and answers 202 with the problem when the person may not publish', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $secret = ManageApiFixture::key($contributor, ['content.write', 'content.publish']);

    $response = $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'Hoping', 'body' => apiBody(), 'status' => 'published'], ManageApiFixture::headers($secret));

    $response->assertStatus(202)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.problem.code', 'forbidden');

    expect($response->json('data.id'))->toBe(DB::table('contents')->value('id'))
        ->and(DB::table('contents')->value('status'))->toBe('draft');
});

it('refuses an unknown field, a bad body, a term of another taxonomy and an author who can’t be credited, writing nothing', function (array $changes, string $parameter): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write']);
    $tag = taxonomyTerm('tag', 'Harbour');
    $reader = User::factory()->create();

    $changes = array_map(fn (mixed $value): mixed => match ($value) {
        'TAG' => $tag->id,
        'READER' => $reader->id,
        default => $value,
    }, $changes);

    if (isset($changes['terms'])) {
        $changes['terms'] = ['category' => [$tag->id]];
    }

    $this->postJson('/api/manage/v1/items', [...['type' => 'post', 'title' => 'Refused', 'body' => apiBody(), 'status' => 'draft'], ...$changes], ManageApiFixture::headers($secret))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_parameter')
        ->assertJsonPath('errors.0.parameter', $parameter);

    expect(apiWritten())->toBe(['contents' => 0, 'revisions' => 0, 'scheduled' => 0]);
})->with([
    'an unknown field handle' => [['fields' => ['focus_keyword' => 'boats']], 'fields.focus_keyword'],
    'a bad body' => [['body' => ['type' => 'doc', 'content' => [['type' => 'marquee']]]], 'body'],
    'no body' => [['body' => null], 'body'],
    'a term of another taxonomy' => [['terms' => 'TAG'], 'terms.category'],
    'an author who can’t be credited' => [['author' => 'READER'], 'author'],
    'an unknown type' => [['type' => 'recipe'], 'type'],
    'an unknown status' => [['status' => 'live'], 'status'],
    'scheduled without a date' => [['status' => 'scheduled'], 'publish_at'],
]);

it('refuses a person who may not create items, writing nothing', function (): void {
    $reader = User::factory()->create();
    $secret = ManageApiFixture::key($reader, ['content.write']);

    $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'No', 'body' => apiBody(), 'status' => 'draft'], ManageApiFixture::headers($secret))
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');

    expect(apiWritten())->toBe(['contents' => 0, 'revisions' => 0, 'scheduled' => 0]);
});

it('needs content.write', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['site.read', 'content.publish']);

    $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'No', 'body' => apiBody(), 'status' => 'draft'], ManageApiFixture::headers($secret))
        ->assertStatus(403);

    expect(apiWritten()['contents'])->toBe(0);
});

it('converts an HTML body to a Tiptap document, keeping its links as given', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write']);

    $response = $this->postJson('/api/manage/v1/items', [
        'type' => 'post',
        'title' => 'From HTML',
        'html' => '<p>Read <a href="https://example.org/guide">the guide</a> and <strong>stay</strong>.</p><h2>More</h2>',
        'status' => 'draft',
    ], ManageApiFixture::headers($secret));

    $response->assertStatus(201);

    $document = json_decode((string) DB::table('content_revisions')->value('document'), true);
    $json = (string) json_encode($document, JSON_UNESCAPED_SLASHES);

    expect($document['type'])->toBe('doc')
        ->and($json)->toContain('https://example.org/guide')
        ->and($json)->toContain('"type":"heading"')
        ->and($json)->not->toContain('<p>');
});

it('stores an HTML body’s links to this site by ID, never as the site’s own address (rule 9)', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write', 'content.publish']);

    $target = $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'Harbour notes', 'body' => apiBody(), 'status' => 'published'], ManageApiFixture::headers($secret))
        ->assertStatus(201);
    $id = (string) $target->json('data.id');
    $url = (string) $target->json('data.url');

    $this->postJson('/api/manage/v1/items', [
        'type' => 'post',
        'title' => 'Linking',
        'html' => '<p>See <a href="'.$url.'#boats">the notes</a> and <a href="'.rtrim(url('/'), '/').'/no-such-page/">a path</a>.</p>',
        'status' => 'draft',
    ], ManageApiFixture::headers($secret))->assertStatus(201);

    $json = (string) json_encode(json_decode((string) DB::table('content_revisions')->latest('created_at')->orderByDesc('id')->value('document'), true), JSON_UNESCAPED_SLASHES);

    expect($url)->toStartWith(rtrim(url('/'), '/'))
        ->and($json)->toContain('"href":"teme:content/'.strtolower($id).'#boats"')
        ->and($json)->toContain('"href":"/no-such-page/"')
        ->and($json)->not->toContain(rtrim(url('/'), '/'));
});

it('refuses both a body and html at once', function (): void {
    $secret = ManageApiFixture::key($this->editor, ['content.write']);

    $this->postJson('/api/manage/v1/items', ['type' => 'post', 'title' => 'Both', 'body' => apiBody(), 'html' => '<p>x</p>', 'status' => 'draft'], ManageApiFixture::headers($secret))
        ->assertStatus(422)->assertJsonPath('errors.0.parameter', 'body');

    expect(apiWritten()['contents'])->toBe(0);
});
