<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Media\MediaRef;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Writer\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('writer');

    $this->enable = fn (array $capabilities = ['content.read', 'content.write']): PluginRecord => PluginRecord::factory()->enabled()->create([
        'name' => 'tests/writer',
        'code' => 'writer',
        'version' => '1.0.0',
        'consent' => [
            'capabilities' => $capabilities,
            'hosts' => [],
            'hooks' => [],
        ],
    ]);

    $this->door = fn (array $capabilities = ['content.write']): ContentWriter => app(PluginDoors::class)
        ->for('tests/writer', 'writer', $capabilities, [])[ContentWriter::class];

    $this->counts = fn (): array => [
        'contents' => Content::query()->count(),
        'revisions' => ContentRevision::query()->count(),
    ];
});

afterEach(fn () => PluginFixtures::forget());

it('offers ContentWriter only when content.write is granted, and a plugin asking for it without that grant fails to start', function (): void {
    $doors = app(PluginDoors::class);
    $granted = $doors->for('tests/writer', 'writer', ['content.read', 'content.write'], []);
    $without = $doors->for('tests/writer', 'writer', ['content.read'], []);

    expect($granted[ContentWriter::class])->toBeInstanceOf(ContentWriter::class)
        ->and($without)->not->toHaveKey(ContentWriter::class);

    ($this->enable)(['content.read']);
    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->running())->not->toHaveKey('tests/writer');
});

it('starts a granted writer plugin with ContentWriter', function (): void {
    ($this->enable)();
    app(PluginLoader::class)->load();
    $plugin = app(LoadedPlugins::class)->running()['tests/writer'] ?? null;

    expect($plugin)->toBeInstanceOf(Writer::class)
        ->and($plugin?->writer)->toBeInstanceOf(ContentWriter::class);
});

it('creates a draft with title, body, fields, terms and featured image, authored by the person, via the plugin', function (): void {
    $editor = User::factory()->create(['name' => 'Ada'])->assignRole('editor');
    $this->actingAs($editor);
    $media = mediaUpload($editor, mediaJpeg(), 'harbour.jpg', allowDuplicate: true);
    $category = taxonomyTerm('category', 'News');
    $writer = ($this->door)();

    $item = $writer->create(new NewItem(
        type: 'post',
        title: 'Harbour notes',
        body: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]],
        fields: ['seo_title' => 'Harbour SEO'],
        terms: ['category' => ['news']],
        featuredImage: new MediaRef(
            (string) $media->id,
            $media->kind,
            $media->mime_type,
            $media->original_filename,
            $media->title,
            $media->size_bytes,
            $media->width,
            $media->height,
        ),
    ), (string) $editor->id);

    $content = Content::query()->findOrFail($item->id);
    $revision = $content->workingRevision;

    expect($item)->toBeInstanceOf(ItemRef::class)
        ->and($item->title)->toBe('Harbour notes')
        ->and($item->status)->toBe('draft')
        ->and($content->author_id)->toBe($editor->id)
        ->and($revision->author_id)->toBe($editor->id)
        ->and($revision->via)->toBe('writer')
        ->and($revision->fields[BuiltInFields::SEO_TITLE])->toBe('Harbour SEO')
        ->and($revision->fields[BuiltInFields::FEATURED_IMAGE]['id'])->toBe((string) $media->id)
        ->and($revision->terms)->toBe([(string) $category->id]);
});

it('changes an item by saving a new revision through the plugin', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $writer = ($this->door)();
    $item = $writer->create(new NewItem('post', 'First', ['type' => 'doc', 'content' => []]), (string) $editor->id);
    $before = ContentRevision::query()->count();

    $changed = $writer->change($item, new ItemChanges(title: 'Second', body: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'More']]]]]), (string) $editor->id);
    $revision = Content::query()->findOrFail($changed->id)->workingRevision;

    expect($changed->title)->toBe('Second')
        ->and(ContentRevision::query()->count())->toBe($before + 1)
        ->and($revision->via)->toBe('writer')
        ->and($revision->title)->toBe('Second');
});

it('publishes and schedules for an editor, and refuses both for a contributor', function (): void {
    Date::setTestNow('2026-11-15 12:00:00');
    $editor = User::factory()->create()->assignRole('editor');
    $contributor = User::factory()->create()->assignRole('contributor');
    $writer = ($this->door)();
    $this->actingAs($editor);

    $toPublish = $writer->create(new NewItem('post', 'Ready', ['type' => 'doc', 'content' => []]), (string) $editor->id);
    $published = $writer->publish($toPublish, (string) $editor->id);

    expect($published->status)->toBe('published');

    $toSchedule = $writer->create(new NewItem('post', 'Later', ['type' => 'doc', 'content' => []]), (string) $editor->id);
    $scheduled = $writer->schedule($toSchedule, new DateTimeImmutable('2026-11-16 09:00:00'), (string) $editor->id);

    expect($scheduled->status)->toBe('scheduled');

    $this->actingAs($contributor);
    $theirs = $writer->create(new NewItem('post', 'Mine', ['type' => 'doc', 'content' => []]), (string) $contributor->id);
    $before = ($this->counts)();

    expect(fn () => $writer->publish($theirs, (string) $contributor->id))->toThrow(WriteRefused::class)
        ->and(fn () => $writer->schedule($theirs, new DateTimeImmutable('2026-11-16 10:00:00'), (string) $contributor->id))->toThrow(WriteRefused::class)
        ->and(($this->counts)())->toBe($before)
        ->and(Content::query()->findOrFail($theirs->id)->status)->toBe('draft');
});

it('uses an existing term by name', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $category = taxonomyTerm('category', 'Harbour News');
    $writer = ($this->door)();

    $item = $writer->create(new NewItem(
        type: 'post',
        title: 'By name',
        body: ['type' => 'doc', 'content' => []],
        terms: ['category' => ['Harbour News']],
    ), (string) $editor->id);

    expect(Content::query()->findOrFail($item->id)->workingRevision->terms)->toBe([(string) $category->id]);
});

it('creates a missing term by name through CreateTerm', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $writer = ($this->door)();
    $before = Term::query()->count();

    $item = $writer->create(new NewItem(
        type: 'post',
        title: 'New term',
        body: ['type' => 'doc', 'content' => []],
        terms: ['category' => ['Fresh Harbour']],
    ), (string) $editor->id);

    $term = Term::query()->where('taxonomy', 'category')->where('name', 'Fresh Harbour')->first();

    expect($term)->not->toBeNull()
        ->and(Term::query()->count())->toBe($before + 1)
        ->and(Content::query()->findOrFail($item->id)->workingRevision->terms)->toBe([(string) $term->id]);
});

it('refuses creating a term when the person may not, and writes nothing', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $this->actingAs($contributor);
    $writer = ($this->door)();
    $before = ($this->counts)();
    $termsBefore = Term::query()->count();

    expect(fn () => $writer->create(new NewItem(
        type: 'post',
        title: 'No terms',
        body: ['type' => 'doc', 'content' => []],
        terms: ['category' => ['Forbidden New']],
    ), (string) $contributor->id))
        ->toThrow(WriteRefused::class)
        ->and(($this->counts)())->toBe($before)
        ->and(Term::query()->count())->toBe($termsBefore);
});

it('refuses when a contributor’s request asks to act for an administrator, and writes nothing', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $admin = User::factory()->create()->assignRole('administrator');
    $writer = ($this->door)();
    $before = ($this->counts)();
    $this->actingAs($contributor);

    expect(fn () => $writer->create(new NewItem('post', 'Hijack', ['type' => 'doc', 'content' => []]), (string) $admin->id))
        ->toThrow(WriteRefused::class)
        ->and(($this->counts)())->toBe($before);
});

it('writes nothing on every refusal', function (string $case): void {
    $editor = User::factory()->create()->assignRole('editor');
    $this->actingAs($editor);
    $writer = ($this->door)();
    $ok = $writer->create(new NewItem('post', 'Anchor', ['type' => 'doc', 'content' => []]), (string) $editor->id);
    $before = ($this->counts)();
    $heard = false;
    app(HookDispatcher::class)->subscriber('tests/listener', ['content.read'], ['ContentSaved'])
        ->on(ContentSaved::class, function () use (&$heard): void {
            $heard = true;
        });

    $run = match ($case) {
        'no person' => fn () => $writer->create(new NewItem('post', 'X', ['type' => 'doc', 'content' => []]), ''),
        'abc' => fn () => $writer->create(new NewItem('post', 'X', ['type' => 'doc', 'content' => []]), 'abc'),
        'unknown person' => fn () => $writer->create(new NewItem('post', 'X', ['type' => 'doc', 'content' => []]), '999999'),
        'no permission' => function () use ($writer): void {
            $stranger = User::factory()->create();
            $this->actingAs($stranger);
            $writer->create(new NewItem('post', 'X', ['type' => 'doc', 'content' => []]), (string) $stranger->id);
        },
        'unknown type' => fn () => $writer->create(new NewItem('nope', 'X', ['type' => 'doc', 'content' => []]), (string) $editor->id),
        'invalid fields' => fn () => $writer->create(new NewItem('post', 'X', ['type' => 'doc', 'content' => []], fields: ['not_a_field' => 'x']), (string) $editor->id),
        'not tip tap' => fn () => $writer->create(new NewItem('post', 'X', ['html' => '<p>no</p>']), (string) $editor->id),
        'change without permission' => function () use ($writer, $ok): void {
            $stranger = User::factory()->create();
            $this->actingAs($stranger);
            $writer->change($ok, new ItemChanges(title: 'Hijack'), (string) $stranger->id);
        },
    };

    expect($run)->toThrow(WriteRefused::class)
        ->and(($this->counts)())->toBe($before)
        ->and($heard)->toBeFalse()
        ->and(Content::query()->findOrFail($ok->id)->title)->toBe('Anchor');
})->with([
    'no person',
    'abc',
    'unknown person',
    'no permission',
    'unknown type',
    'invalid fields',
    'not tip tap',
    'change without permission',
]);
