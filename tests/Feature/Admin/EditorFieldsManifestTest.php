<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\FieldType;
use App\Domain\Fields\Types\BooleanField;
use App\Domain\Fields\Types\CanonicalField;
use App\Domain\Fields\Types\DatetimeField;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Fields\Types\MediaField;
use App\Domain\Fields\Types\NumberField;
use App\Domain\Fields\Types\SelectField;
use App\Domain\Fields\Types\TermField;
use App\Domain\Fields\Types\TextareaField;
use App\Domain\Fields\Types\TextField;
use App\Domain\Fields\Types\UrlField;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    $this->post = lifecycleDraft($this->editor, 'Harbour news');
});

it('gives the editor each field’s control and settings beside what it had', function (): void {
    $response = $this->actingAs($this->editor)->get("/admin/content/{$this->post->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->component('content/edit')->has('fields', 18));
    // By handle, never by position: later parts may add fields.
    $fields = collect($response->inertiaProps('fields'))->keyBy('handle');

    expect($fields['seo_title'])->toBe([
        'key' => BuiltInFields::SEO_TITLE, 'handle' => 'seo_title', 'label' => 'SEO title',
        'description' => 'The title search engines show. Leave empty to use the item\'s title.',
        'type' => 'text', 'required' => false, 'maxLength' => 200, 'options' => [],
        'place' => 'core', 'component' => 'text', 'settings' => ['maxLength' => 200],
    ])
        ->and($fields['page_type']['settings']['options'][1])->toBe(['value' => 'WebPage', 'label' => 'Web page'])
        ->and($fields['primary_category']['component'])->toBeNull();
});

it('lists a field placed in Details for its own type only', function (): void {
    app(FieldRegistry::class)->register(new Field('01K6A0P1CE000000000000FC02', 'running_time', 'Running time', 'In minutes.', new NumberField, ['post'], '0.2.0'));
    $page = lifecycleDraft($this->editor, 'About', 'page');

    $post = collect($this->actingAs($this->editor)->get("/admin/content/{$this->post->id}/edit")->inertiaProps('fields'))->keyBy('handle');
    $pageFields = collect($this->actingAs($this->editor)->get("/admin/content/{$page->id}/edit")->inertiaProps('fields'))->keyBy('handle');

    expect($post)->toHaveCount(19)
        ->and($post['running_time']['place'])->toBe('details')
        ->and($post['running_time']['component'])->toBe('number')
        ->and($post['running_time']['settings'])->toBe([])
        ->and($pageFields)->toHaveCount(17)
        ->and($pageFields->has('running_time'))->toBeFalse();
});

it('has a control in the browser for every control a field type names, but the ones 2.2b adds', function (): void {
    // 2.2b draws these and empties the list.
    $deferredTo22b = [];
    $types = [new TextField, new TextareaField, new NumberField, new BooleanField, new DatetimeField, new SelectField, new UrlField, new MediaField, new EntryField, new TermField, new CanonicalField];
    $named = array_values(array_unique(array_filter(array_map(fn (FieldType $type): ?string => $type->edit(new Field('01K6A0P1CE000000000000FC03', 'any', 'Any', 'Any.', $type, [], '0.2.0'))['component'], $types))));
    preg_match_all('/^\s{4}([a-z_]+): /m', (string) file_get_contents(resource_path('js/components/editor/fields/controls.ts')), $matches);

    expect($matches[1])->toEqualCanonicalizing(array_values(array_diff($named, $deferredTo22b)))
        ->and(array_intersect($matches[1], $deferredTo22b))->toBe([]);
});

it('puts a media field’s image in the session on the first paint, and leaves the body’s linked items alone', function (): void {
    $registry = app(FieldRegistry::class);
    $registry->register(new Field('01K6A0P1CE000000000000FC04', 'poster', 'Poster', 'An image.', new MediaField, ['post'], '0.2.0'));
    $registry->register(new Field('01K6A0P1CE000000000000FC05', 'sequel', 'Sequel', 'Another item.', new EntryField, ['post'], '0.2.0'));
    $image = pageImage($this->editor, 1200, 630);
    $target = addrPublished($this->editor, 'Part two');
    $draft = refsDraft($this->editor, [
        '01K6A0P1CE000000000000FC04' => ['id' => $image->id, 'alt' => null, 'decorative' => false],
        '01K6A0P1CE000000000000FC05' => $target->id,
    ]);

    $session = EditorSession::for($draft->id, (string) $this->editor->id);

    expect(array_keys($session->media))->toBe([$image->id])
        ->and($session->linked)->toBe([]);
});

it('still has the featured and social images in the session', function (): void {
    $featured = pageImage($this->editor, 1200, 630);
    $social = pageImage($this->editor, 1200, 630);
    $draft = refsDraft($this->editor, [
        BuiltInFields::FEATURED_IMAGE => ['id' => $featured->id, 'alt' => null, 'decorative' => false],
        BuiltInFields::SOCIAL_IMAGE => ['id' => $social->id, 'alt' => null, 'decorative' => false],
    ]);

    $session = EditorSession::for($draft->id, (string) $this->editor->id);

    expect(array_keys($session->media))->toEqualCanonicalizing([$featured->id, $social->id]);
});

it('gives the editor a stored field placed in Details, and a date field’s date-only setting', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry('01K9F1E1D0000000000000MF01', 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]), 1);
    $movie = lifecycleDraft($this->editor, 'Arrival', 'movie');

    $fields = collect($this->actingAs($this->editor)->get("/admin/content/{$movie->id}/edit")->inertiaProps('fields'))->keyBy('handle');

    expect($fields['release_date']['place'])->toBe('details')
        ->and($fields['release_date']['component'])->toBe('datetime')
        ->and($fields['release_date']['settings'])->toBe(['dateOnly' => true])
        ->and($fields['release_date']['required'])->toBeTrue();
});

it('gives the session the titles of the items its entry fields point at, apart from the body’s links', function (): void {
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry('01K9F1E1D0000000000000MF02', 'sequel', ['fieldType' => 'entry']), 1);
    $target = lifecycleDraft($this->editor, 'Arrival', 'movie');
    app(PublishRevision::class)->execute(new PublishRevisionInput($target->id, (string) $target->working_revision_id, (string) $this->editor->id));
    $movie = lifecycleDraft($this->editor, 'Arrival Two', 'movie');
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $movie->title, ['type' => 'doc', 'content' => []], ['01K9F1E1D0000000000000MF02' => $target->id], $movie->working_revision_id, (string) $this->editor->id));

    $session = EditorSession::for($movie->id, (string) $this->editor->id);

    expect($session->entries)->toBe([$target->id => ['title' => 'Arrival', 'type' => 'movie', 'status' => 'published']])
        ->and($session->linked)->toBe([]);
});
