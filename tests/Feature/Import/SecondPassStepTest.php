<?php

declare(strict_types=1);

use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\SecondPass\SecondPassStep;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\SecondPassImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    ContentImports::person($this->source->id, $this->author, 3);
});

/**
 * The item WordPress's post or page $wordpressId became.
 */
function spItem(string $sourceId, int $wordpressId): Content
{
    return Content::withTrashed()->findOrFail(ContentImports::row($sourceId, $wordpressId)?->teme_id);
}

/**
 * Every link address in an item's working revision, in order.
 *
 * @return list<string>
 */
function spLinks(Content $item): array
{
    $document = ContentRevision::query()->findOrFail($item->refresh()->working_revision_id)->document;
    $found = [];
    $walk = function (array $nodes) use (&$walk, &$found): void {
        foreach ($nodes as $node) {
            foreach ((array) ($node['marks'] ?? []) as $mark) {
                if (($mark['type'] ?? null) === 'link') {
                    $found[] = (string) $mark['attrs']['href'];
                }
            }

            $walk((array) ($node['content'] ?? []));
        }
    };
    $walk((array) ($document['content'] ?? []));

    return $found;
}

/**
 * WordPress's post 15, "Later story", at /later-story/.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array<string, mixed>
 */
function spLater(array $fields = []): array
{
    return ContentImports::wpItem(15, ['slug' => 'later-story', 'title' => 'Later story', 'permalink' => 'https://old.example/later-story/', ...$fields]);
}

it('puts a reusable block’s words where a post used it, in a revision of the import’s, its row keeping the fingerprint (I-333)', function (): void {
    $run = SecondPassImports::run($this->companion, $this->source, [SecondPassImports::block(88), SecondPassImports::using(12, 88)]);
    $item = spItem($this->source->id, 12);
    $row = ContentImports::row($this->source->id, 12);
    $latest = ContentRevision::query()->findOrFail($item->working_revision_id);

    expect($run->refresh()->state)->toBe(ImportRunState::Finished)
        ->and(SecondPassImports::words($item))->toBe('Boats came in at dawn. Open daily from nine. The tide turned at noon.')
        ->and(ContentRevision::query()->where('content_id', $item->id)->count())->toBe(2)
        ->and($latest->origin)->toBe('import')
        ->and($item->published_revision_id)->toBe($latest->id)
        ->and($row?->facts['written'])->toBe(ImportedContents::fingerprint($item))
        ->and($row?->facts['passed']['open'])->toBeFalse()
        ->and($row?->facts['passed']['to'])->toBe((string) $latest->id)
        ->and(array_filter(ContentImports::codes($row), fn (string $code): bool => str_starts_with($code, 'second-pass.')))->toBe([]);
});

it('a rerun writes nothing (I-1980)', function (): void {
    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::block(88), SecondPassImports::using(12, 88)]);
    $passed = ContentImports::row($this->source->id, 12)?->facts['passed'];
    $this->travel(5)->seconds();

    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::block(88), SecondPassImports::using(12, 88)]);

    expect(ContentRevision::query()->where('content_id', spItem($this->source->id, 12)->id)->count())->toBe(2)
        ->and(ContentImports::row($this->source->id, 12)?->facts['passed'])->toEqual($passed);
});

it('turns a link to a post the import brought later into a link by ID (I-1702)', function (): void {
    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::linking(12, 'https://old.example/later-story/'), spLater()]);
    $later = spItem($this->source->id, 15);

    expect(spLinks(spItem($this->source->id, 12)))->toBe(['teme:content/'.$later->id])
        ->and(ContentImports::row($this->source->id, 12)?->facts['passed']['open'])->toBeFalse()
        ->and(DB::table('content_links')->where('from_content_id', spItem($this->source->id, 12)->id)->where('to_content_id', $later->id)->exists())->toBeTrue();
});

it('follows an old attachment page’s redirect to what it leads to (I-244)', function (): void {
    $target = ContentImports::import(ContentImports::item($this->author, ['title' => 'Boats', 'slug' => 'boats', 'wordpressPath' => 'boats', 'guid' => null]), $this->admin);
    scElevated(fn (): array => app(ImportRedirect::class)->execute(new ImportRedirectInput(from: '/harbour-news-12/boat-photo/', contentId: $target['contentId'])));

    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::linking(12, 'https://old.example/harbour-news-12/boat-photo/')]);

    expect(spLinks(spItem($this->source->id, 12)))->toBe(['teme:content/'.$target['contentId']]);
});

it('keeps a link nothing answers as a path, says so once, and a catch-up that brings what it leads to finishes it', function (): void {
    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::linking(12, 'https://old.example/later-story/')]);
    $row = ContentImports::row($this->source->id, 12);

    expect(spLinks(spItem($this->source->id, 12)))->toBe(['/later-story/'])
        ->and(ContentImports::codes($row))->toContain('second-pass.links-unresolved')
        ->and($row?->facts['passed']['open'])->toBeTrue();

    $this->travel(5)->seconds();
    $catchUp = SecondPassImports::run($this->companion, $this->source, [spLater(['modified_gmt' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')])], ImportRunKind::CatchUp);
    $row = ContentImports::row($this->source->id, 12);

    expect(spLinks(spItem($this->source->id, 12)))->toBe(['teme:content/'.spItem($this->source->id, 15)->id])
        ->and($row?->run_id)->toBe($catchUp->id)
        ->and($row?->outcome)->toBe(ImportOutcome::Updated)
        ->and(ContentImports::codes($row))->not->toContain('second-pass.links-unresolved')
        ->and($row?->facts['passed']['open'])->toBeFalse();
});

it('sets a canonical left for the second pass to the item it became (rule 9)', function (): void {
    SecondPassImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['seo' => ['canonical' => 'https://old.example/later-story/']]),
        spLater(),
    ]);
    $fields = ContentRevision::query()->findOrFail(spItem($this->source->id, 12)->working_revision_id)->fields;

    expect($fields[BuiltInFields::CANONICAL] ?? null)->toEqual(['item' => spItem($this->source->id, 15)->id])
        ->and(ContentImports::row($this->source->id, 12)?->facts['passed']['open'])->toBeFalse();
});

it('says a reusable block it couldn’t find, leaving nothing in its place', function (): void {
    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::using(12, 90)]);
    $row = ContentImports::row($this->source->id, 12);

    expect(SecondPassImports::words(spItem($this->source->id, 12)))->toBe('Boats came in at dawn. The tide turned at noon.')
        ->and(ContentImports::codes($row))->toContain('second-pass.block-missing')
        ->and($row?->facts['passed']['open'])->toBeTrue();
});

it('a catch-up that brings a changed block writes it into every post that uses it, and leaves a post changed here alone', function (): void {
    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::using(12, 88), SecondPassImports::using(13, 88), SecondPassImports::block(88)]);
    DB::table('contents')->where('id', spItem($this->source->id, 13)->id)->update(['slug' => 'changed-here']);
    $this->travel(5)->seconds();

    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::block(88, 'Open daily from ten.', ['modified_gmt' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')])], ImportRunKind::CatchUp);

    expect(SecondPassImports::words(spItem($this->source->id, 12)))->toBe('Boats came in at dawn. Open daily from ten. The tide turned at noon.')
        ->and(SecondPassImports::words(spItem($this->source->id, 13)))->toBe('Boats came in at dawn. Open daily from nine. The tide turned at noon.');
});

it('a catch-up that changes only a block inside another block still reaches the post that uses the outer one', function (): void {
    $outer = ['blocks' => [wpBlock('core/paragraph', '<p>Opening hours:</p>'), wpBlock('core/block', '', ['ref' => 90])]];
    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::using(12, 88), SecondPassImports::block(88, '', $outer), SecondPassImports::block(90)]);

    expect(SecondPassImports::words(spItem($this->source->id, 12)))->toBe('Boats came in at dawn. Opening hours: Open daily from nine. The tide turned at noon.')
        ->and(ContentImports::row($this->source->id, 12)?->facts['passed']['blocks'])->toEqualCanonicalizing(['88', '90']);

    $this->travel(5)->seconds();
    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::block(90, 'Open daily from ten.', ['modified_gmt' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')])], ImportRunKind::CatchUp);

    expect(SecondPassImports::words(spItem($this->source->id, 12)))->toBe('Boats came in at dawn. Opening hours: Open daily from ten. The tide turned at noon.');
});

it('puts the blocks in for a person’s run as for the operator’s (I-392)', function (): void {
    $run = SecondPassImports::run($this->companion, $this->source, [SecondPassImports::block(88), SecondPassImports::using(12, 88)], ImportRunKind::Full, $this->admin);

    expect($run->refresh()->state)->toBe(ImportRunState::Finished)
        ->and((int) $run->started_by)->toBe($this->admin->id)
        ->and(SecondPassImports::words(spItem($this->source->id, 12)))->toBe('Boats came in at dawn. Open daily from nine. The tide turned at noon.');
});

it('leaves an item changed here as it is, saying so when this run wrote it (I-332)', function (): void {
    // A person's save between the content phase and the second pass, played by a step at 550.
    $meddle = new class implements StepPhase
    {
        public function key(): string
        {
            return 'meddle';
        }

        public function order(): int
        {
            return 550;
        }

        public function label(): string
        {
            return 'Meddle';
        }

        public function runsIn(ImportRunKind $kind): bool
        {
            return true;
        }

        public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
        {
            DB::table('contents')->update(['slug' => 'changed-here']);

            return StepResult::done();
        }
    };

    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::block(88), SecondPassImports::using(12, 88)], ImportRunKind::Full, null, app(SecondPassStep::class), $meddle);

    expect(ContentRevision::query()->where('content_id', spItem($this->source->id, 12)->id)->count())->toBe(1)
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toContain('second-pass.changed-here');
});

it('keeps an item as the content phase wrote it when its finished words are too large to save, saying why', function (): void {
    $earlier = ImportRecords::run($this->source->id);
    $paragraphs = array_fill(0, 10100, ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Again.']]]);
    ImportRecords::entry($this->source->id, $earlier, 'post', '88', 'skipped', [
        'facts' => json_encode(['kind' => 'reusable', 'document' => $paragraphs, 'references' => [], 'pending_links' => []]),
    ]);

    SecondPassImports::run($this->companion, $this->source, [SecondPassImports::using(12, 88)]);
    $row = ContentImports::row($this->source->id, 12);

    expect(ContentRevision::query()->where('content_id', spItem($this->source->id, 12)->id)->count())->toBe(1)
        ->and(ContentImports::codes($row))->toContain('second-pass.refused')
        ->and($row?->facts['passed']['open'])->toBeFalse();
});

it('finishes a page as it finishes a post, and a draft without publishing it', function (): void {
    SecondPassImports::run($this->companion, $this->source, [
        SecondPassImports::using(12, 88, ['type' => 'page', 'slug' => 'about', 'title' => 'About', 'permalink' => 'https://old.example/about/']),
        SecondPassImports::using(13, 88, ['status' => 'draft', 'permalink' => 'https://old.example/?p=13']),
        SecondPassImports::block(88),
    ]);
    $draft = spItem($this->source->id, 13);

    expect(SecondPassImports::words(spItem($this->source->id, 12)))->toContain('Open daily from nine.')
        ->and(SecondPassImports::words($draft))->toContain('Open daily from nine.')
        ->and($draft->status)->toBe('draft')
        ->and($draft->published_revision_id)->toBeNull();
});

it('finishes a link a reusable block left for it', function (): void {
    // Block 14 is converted before post 15 exists, so its link waits for the second pass.
    SecondPassImports::run($this->companion, $this->source, [
        SecondPassImports::using(12, 14),
        SecondPassImports::block(14, 'See <a href="https://old.example/later-story/">the later story</a>.'),
        spLater(),
    ]);

    expect(ContentImports::row($this->source->id, 14)?->facts['pending_links'])->toBe(['/later-story/'])
        ->and(spLinks(spItem($this->source->id, 12)))->toBe(['teme:content/'.spItem($this->source->id, 15)->id]);
});

it('is registered at 600, between posts and comments, in every kind of run', function (): void {
    $step = app(ImportPhases::class)->find('second-pass');

    expect($step)->toBeInstanceOf(SecondPassStep::class)
        ->and($step?->order())->toBe(600)
        ->and($step?->runsIn(ImportRunKind::Full))->toBeTrue()
        ->and($step?->runsIn(ImportRunKind::CatchUp))->toBeTrue()
        ->and(app(ImportPhases::class)->find('content')?->order())->toBeLessThan(600);
});
