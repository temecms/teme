<?php

declare(strict_types=1);

use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('finds an article by a word in its title', function (): void {
    lifecycleDraft($this->editor, 'Harbour festival returns');

    $this->actingAs($this->editor)->getJson('/admin/search?q=harbour')
        ->assertOk()->assertJsonPath('content.0.title', 'Harbour festival returns');
});

it('finds a long title by one short word in it, as the list does', function (): void {
    lifecycleDraft($this->editor, 'Harbour festival returns for a third year with night markets');

    $this->actingAs($this->editor)->getJson('/admin/search?q=harbour')
        ->assertOk()->assertJsonPath('content.0.title', 'Harbour festival returns for a third year with night markets');
});

it('leaves the text to the list, and offers its In text search', function (): void {
    $content = lifecycleDraft($this->editor, 'Untitled news');
    docSave($content, $this->editor, docWith(docText('The lifeboat crew marked fifty years of service.')));
    listsPublish($content->refresh(), $this->editor);

    // No body match in the palette: no plan can bound it at a million rows (B-199).
    $url = $this->actingAs($this->editor)->getJson('/admin/search?q=lifeboat')
        ->assertOk()
        ->assertJsonPath('content', [])
        ->assertJsonPath('textSearch.plural', 'Posts')
        ->json('textSearch.url');

    expect($url)->toBe(route('content.index', ['type' => 'posts', 'search' => 'lifeboat', 'in' => 'body'], false));

    $this->actingAs($this->editor)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.id', $content->id));
});

it('ranks a title by the word it holds, not by how short it is', function (): void {
    lifecycleDraft($this->editor, 'Harbourside');
    lifecycleDraft($this->editor, 'The harbour festival returns for a third year with night markets');

    // Whole-title similarity puts the short title first; word similarity
    // puts the title holding the whole word first.
    $this->actingAs($this->editor)->getJson('/admin/search?q=harbour')
        ->assertOk()
        ->assertJsonPath('content.0.title', 'The harbour festival returns for a third year with night markets')
        ->assertJsonPath('content.1.title', 'Harbourside');
});

it('filters and orders the titles with one trigram index scan', function (string $query): void {
    lifecycleDraft($this->editor, 'Harbour festival returns');
    DB::statement('ANALYZE contents');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($this->editor)->getJson('/admin/search?q='.urlencode($query))->assertOk();
    $titles = collect(DB::getQueryLog())->first(fn (array $entry): bool => str_contains($entry['query'], '<<->'));
    DB::disableQueryLog();

    $plan = DB::transaction(function () use ($titles): string {
        DB::statement('SET LOCAL enable_seqscan = off');
        // A bitmap scan can't give the order, so the plan left shows whether the trigram index can.
        DB::statement('SET LOCAL enable_bitmapscan = off');

        return collect(DB::select('EXPLAIN '.$titles['query'], $titles['bindings']))->pluck('QUERY PLAN')->implode('
');
    });

    expect($plan)->toContain('Index Scan using contents_title_trgm_gist')
        ->and($plan)->toContain('Order By')
        ->and($plan)->not->toContain('Seq Scan')
        ->and($plan)->not->toContain('Sort Key');
})->with(['har', 'lifeboat']);

it('finds media, terms and users too', function (): void {
    $administrator = User::factory()->create()->assignRole('administrator');
    mediaUpload($administrator, mediaJpeg(), 'Harbour at dawn.jpg');
    taxonomyTerm('category', 'Harbour news');
    User::factory()->create(['name' => 'Harriet Harbourne']);

    $this->actingAs($administrator)->getJson('/admin/search?q=harbour')
        ->assertOk()
        ->assertJsonPath('media.0.title', 'Harbour at dawn')
        ->assertJsonPath('media.0.kind', 'media')
        ->assertJsonPath('terms.0.title', 'Harbour news')
        ->assertJsonPath('terms.0.kind', 'term')
        ->assertJsonPath('users.0.title', 'Harriet Harbourne')
        ->assertJsonPath('users.0.kind', 'user');
});

it('offers a group only to someone who may open its screen', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    User::factory()->create(['name' => 'Harriet Harbourne']);

    $this->actingAs($contributor)->getJson('/admin/search?q=harbour')
        ->assertOk()
        ->assertJsonPath('users', []);
});

it('finds a setting by its name and opens it at its field, leaving the Settings pages to Go to', function (): void {
    $administrator = User::factory()->create()->assignRole('administrator');

    $this->actingAs($administrator)->getJson('/admin/search?q='.urlencode('time zone'))
        ->assertOk()
        ->assertJsonPath('settings.0', [
            'id' => 'general.timezone',
            'title' => 'Time zone',
            'subtitle' => 'Settings › General',
            'url' => route('settings.show', ['group' => 'general'], false).'#field-timezone',
            'kind' => 'setting',
        ]);
});

it('shows a contributor the same items the Posts list shows them, with edit only where they may edit', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $theirs = lifecycleDraft($contributor, 'Harbour notes');
    $others = lifecycleDraft($this->editor, 'Harbour festival');

    $listed = collect(app(ContentList::class)->page(new ContentListRequest(type: 'post', search: 'harbour', viewerId: $contributor->id))['rows'])
        ->pluck('id')->sort()->values()->all();

    $found = $this->actingAs($contributor)->getJson('/admin/search?q=harbour')->assertOk()->json('content');

    expect(collect($found)->pluck('id')->sort()->values()->all())->toBe($listed)
        ->and(collect($found)->firstWhere('id', $theirs->id)['canEdit'])->toBeTrue()
        ->and(collect($found)->firstWhere('id', $others->id)['canEdit'])->toBeFalse();
});

it('needs no search to offer where to go and what to do', function (): void {
    // Go to and Do come from the shared props every admin page already has.
    $this->actingAs($this->editor)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('nav.0.label')
            ->where('contentTypes.0.singular', 'Post')
            ->where('contentTypes.0.canCreate', true));
});

it('answers an empty or whitespace query with empty groups and runs no search', function (string $query): void {
    $sql = palQueries($this->editor, $query);

    $this->actingAs($this->editor)->getJson('/admin/search?q='.urlencode($query))
        ->assertOk()
        ->assertExactJson(['content' => [], 'media' => [], 'terms' => [], 'users' => [], 'settings' => [], 'textSearch' => null]);

    // Every group matches with LIKE or ILIKE, and content's body with
    // search_tsv; the middleware's own reads of `users` use neither.
    expect(collect($sql)->filter(fn (string $statement): bool => (bool) preg_match('/\b(i?like|search_tsv)\b/i', $statement)))->toBeEmpty();
})->with(['', '   ']);

it('takes the same number of queries whatever the result count', function (): void {
    lifecycleDraft($this->editor, 'Harbour one');
    palQueries($this->editor, 'harbour'); // warms the permission cache

    $one = count(palQueries($this->editor, 'harbour'));

    foreach (range(2, 6) as $n) {
        lifecycleDraft($this->editor, "Harbour {$n}");
    }

    expect(count(palQueries($this->editor, 'harbour')))->toBe($one);
});

it('is rate limited at 60 a minute per person', function (): void {
    foreach (range(1, 60) as $n) {
        $this->actingAs($this->editor)->getJson('/admin/search?q=')->assertOk();
    }

    $this->actingAs($this->editor)->getJson('/admin/search?q=')->assertTooManyRequests();

    $other = User::factory()->create()->assignRole('editor');
    $this->actingAs($other)->getJson('/admin/search?q=')->assertOk();
});

it('offers New {type} only to someone who may create that type', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $nobody = User::factory()->create();

    $this->actingAs($contributor)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('contentTypes.0.canCreate', true));

    $this->actingAs($nobody)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('contentTypes.0.canCreate', false));
});
