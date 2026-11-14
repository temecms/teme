<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ReindexSearchInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\ReindexSearch;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Types\Operations\ImportTypes;
use App\Domain\Types\Operations\Input\ImportTypesInput;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FBS_RATING = '01K9F1E1D0000000000000RA03';
const FBS_RUNTIME = '01K9F1E1D0000000000000RT02';
const FBS_TAGLINE = '01K9F1E1D0000000000000TG05';

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FBS_RATING, 'rating', ['fieldType' => 'select', 'search' => true, 'settings' => ['choices' => [['value' => 'pg-13', 'label' => 'PG-13'], ['value' => 'r', 'label' => 'R']]]]), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FBS_RUNTIME, 'runtime', ['fieldType' => 'number']), 2);
    StoredFieldRows::store(StoredFieldRows::entry(FBS_TAGLINE, 'tagline', ['search' => false]), 3);
});

/**
 * A movie draft holding these field values, published unless told not to.
 *
 * @param  array<string, mixed>  $fields
 */
function fbsMovie(User $author, string $title, array $fields, bool $publish = true): Content
{
    $movie = lifecycleDraft($author, $title, 'movie')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => []], $fields, $movie->working_revision_id, (string) $author->id));

    if ($publish) {
        listsPublish($movie, $author);
    }

    return $movie->refresh();
}

it('finds a published movie by its searchable fields’ words, and not by a field that doesn’t search', function (): void {
    $movie = fbsMovie($this->editor, 'Arrival', [FBS_RATING => 'pg-13', FBS_RUNTIME => 116, FBS_TAGLINE => 'Zebra']);

    expect(searchIds('PG-13'))->toBe([$movie->id])
        ->and(searchIds('Zebra'))->toBe([]);
});

it('changes no site’s results: built-in fields stay out', function (): void {
    $post = lifecycleDraft($this->editor, 'Plain post')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($post->id, 'Plain post', ['type' => 'doc', 'content' => []], [BuiltInFields::SEO_TITLE => 'Wombat', BuiltInFields::EXCERPT => 'Quokka'], $post->working_revision_id, (string) $this->editor->id));
    listsPublish($post, $this->editor);

    expect(searchIds('Wombat'))->toBe([])
        ->and(searchIds('Quokka'))->toBe([]);
});

it('finds no draft’s field words', function (): void {
    fbsMovie($this->editor, 'Arrival', [FBS_RATING => 'pg-13'], publish: false);

    expect(searchIds('PG-13'))->toBe([]);
});

it('rebuilds the type’s search when a file turns a field’s search on, as the operator', function (): void {
    $movie = fbsMovie($this->editor, 'Arrival', [FBS_TAGLINE => 'Zebra']);
    expect(searchIds('Zebra'))->toBe([]);
    Queue::fake();
    $file = ctFile([ctDefinition(['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'icon' => 'film', 'taxonomies' => [], 'supports' => []])->toArray()], ['fields' => [
        StoredFieldRows::entry(FBS_RATING, 'rating', ['fieldType' => 'select', 'search' => true, 'settings' => ['choices' => [['value' => 'pg-13', 'label' => 'PG-13'], ['value' => 'r', 'label' => 'R']]]]),
        StoredFieldRows::entry(FBS_RUNTIME, 'runtime', ['fieldType' => 'number']),
        StoredFieldRows::entry(FBS_TAGLINE, 'tagline', ['search' => true]),
    ]]);

    scElevated(fn () => app(ImportTypes::class)->execute(new ImportTypesInput($file)));

    Queue::assertPushed(ReindexSearchJob::class, fn (ReindexSearchJob $job): bool => $job->actor->isOperator());
    expect(DB::table('contents')->where('id', $movie->id)->value('search_config'))->toBeNull();

    $job = null;
    Queue::assertPushed(ReindexSearchJob::class, function (ReindexSearchJob $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });
    Queue::fake([]);
    scElevated(fn () => $job->handle());

    expect(searchIds('Zebra'))->toBe([$movie->id])
        ->and(DB::table('contents')->where('id', $movie->id)->value('search_config'))->not->toBeNull();
});

it('lets a person who manages types rebuild search, and still refuses one who doesn’t', function (): void {
    $movie = fbsMovie($this->editor, 'Arrival', [FBS_RATING => 'pg-13']);
    $typesOnly = User::factory()->create()->assignRole(Role::create(['name' => 'types-only'])->givePermissionTo('types.manage'));
    DB::table('contents')->where('id', $movie->id)->update(['search_config' => null]);

    expect(fn () => app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $this->editor->id)))->toThrow(AuthorizationException::class)
        ->and(DB::table('contents')->where('id', $movie->id)->value('search_config'))->toBeNull()
        ->and(app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $typesOnly->id)))->toBe(['reindexed' => 1, 'remaining' => 0])
        ->and(DB::table('contents')->where('id', $movie->id)->value('search_config'))->not->toBeNull();
});
