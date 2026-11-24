<?php

declare(strict_types=1);

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\Phases\Addresses\AddressClashStep;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportStopped;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\OldSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The clash step walked to its end as the operator: what it said. It never stops the run.
 *
 * @return list<ImportMessage>
 */
function acsSaid(string $source, string $run, OldSite $site, ?string $token = null): array
{
    return scElevated(fn (): array => OldSite::walk(app(AddressClashStep::class), OldSite::context($source, $run), $site, $token));
}

it('lists, as warnings, each address of a post or page Teme already gives something else, and carries on (I-1706, #704)', function (): void {
    [$source, $run] = OldSite::running('address-clash');
    addrPublished($this->admin, 'About', 'page');
    $site = (new OldSite)->urls('post', [['id' => 2, 'type' => 'page', 'url' => OldSite::HOME.'/about/'], ['id' => 3, 'type' => 'post', 'url' => OldSite::HOME.'/fresh/']]);

    expect(array_map(fn (ImportMessage $message): array => [$message->code, $message->level, $message->message, $message->context], acsSaid($source, $run, $site)))->toBe([
        ['clash.address', ImportMessageLevel::Warning, '/about/ is the page "About" in Teme, so a page from the old site gets another address.', ['address' => '/about/', 'kind' => 'post', 'id' => 2]],
    ])
        ->and(DB::table('settings_changes')->count())->toBe(0);
});

it('stops the run for a category or tag whose address something else holds, which Teme can’t move it from (I-1706)', function (): void {
    [$source, $run] = OldSite::running('address-clash');
    addrPublished($this->admin, 'About', 'page');
    DB::table('paths')->insert(['path' => 'news', 'target_type' => 'term', 'target_id' => taxonomyTerm('category', 'News')->id, 'is_current' => true]);
    $site = (new OldSite)
        ->urls('post', [['id' => 2, 'type' => 'page', 'url' => OldSite::HOME.'/about/']])
        ->urls('term', [['id' => 5, 'taxonomy' => 'post_tag', 'url' => OldSite::HOME.'/news/']]);

    $stop = null;

    try {
        acsSaid($source, $run, $site);
    } catch (ImportStopped $caught) {
        $stop = $caught;
    }

    expect($stop?->reason)->toBe('address-clash')
        ->and($stop?->words)->toBe('A category or tag of the old site has an address something else in Teme already has, which Teme can’t move it from, so the import stopped before writing anything. The report names it: rename or delete what Teme has there, then carry on the import.')
        ->and(array_map(fn (ImportMessage $message): array => [$message->level, $message->message], $stop?->details ?? []))->toBe([
            [ImportMessageLevel::Warning, '/about/ is the page "About" in Teme, so a page from the old site gets another address.'],
            [ImportMessageLevel::Error, '/news/ is the category "News" in Teme, and the old site has the tag there.'],
        ]);
});

it('says nothing of a category Teme already has at the same address, which the import files its posts under (#704)', function (): void {
    [$source, $run] = OldSite::running('address-clash');
    DB::table('paths')->insert(['path' => 'category/health', 'target_type' => 'term', 'target_id' => taxonomyTerm('category', 'Health')->id, 'is_current' => true]);
    $site = (new OldSite)->urls('term', [['id' => 7, 'taxonomy' => 'category', 'url' => OldSite::HOME.'/category/health/']]);

    expect(acsSaid($source, $run, $site))->toBe([]);
});

it('leaves out what this import made, what it doesn’t bring, other hosts and addresses with a query', function (): void {
    [$source, $run] = OldSite::running('address-clash');
    $ours = addrPublished($this->admin, 'Harbour news');
    ImportRecords::entry($source, $run, 'post', '9', 'created', ['teme_type' => 'content', 'teme_id' => $ours->id]);
    addrPublished($this->admin, 'Ferry fares');
    $site = (new OldSite)
        ->urls('post', [
            ['id' => 9, 'type' => 'post', 'url' => OldSite::HOME.'/harbour-news/'],
            ['id' => 10, 'type' => 'product', 'url' => OldSite::HOME.'/ferry-fares/'],
            ['id' => 11, 'type' => 'post', 'url' => 'https://cdn.example/ferry-fares/'],
            ['id' => 12, 'type' => 'post', 'url' => OldSite::HOME.'/?p=12'],
        ])
        ->urls('term', [['id' => 4, 'taxonomy' => 'post_format', 'url' => 'https://www.old.example/ferry-fares/']]);

    expect(acsSaid($source, $run, $site))->toBe([])
        ->and($site->urlRequests())->toBe(['post:0', 'term:0']);
});

it('starts again from the beginning with its own earlier messages cleared', function (): void {
    [$source, $run, $token] = OldSite::running('address-clash');
    ImportRecords::message($run, null, 'clash.address', 'An earlier attempt’s clash.', 'error');
    ImportRecords::message($run, null, 'check.not-found', 'Another step’s.', 'error');

    expect(acsSaid($source, $run, (new OldSite)->urls('post', [['id' => 2, 'type' => 'page', 'url' => OldSite::HOME.'/about/']]), $token))->toBe([])
        ->and(DB::table('import_messages')->pluck('message')->all())->toBe(['Another step’s.']);
});

it('reads the old site a page at a time, and carries on at its cursor', function (): void {
    [$source, $run] = OldSite::running('address-clash');
    $posts = array_map(fn (int $id): array => ['id' => $id, 'type' => 'post', 'url' => OldSite::HOME."/post-{$id}/"], range(1, 501));
    $site = (new OldSite)->urls('post', $posts)->urls('term', [['id' => 1, 'taxonomy' => 'category', 'url' => OldSite::HOME.'/category/one/']]);

    expect(acsSaid($source, $run, $site))->toBe([])
        ->and($site->urlRequests())->toBe(['post:0', 'post:500', 'term:0']);

    // The cursor keeps how many clashes stop the run so far, in front.
    $later = new OldSite;
    $result = scElevated(fn () => app(AddressClashStep::class)->run(OldSite::context($source, $run), $later, '0|term:0'));

    expect($later->urlRequests())->toBe(['term:0'])
        ->and($result->cursor)->toBeNull()
        ->and($result->messages)->toBe([]);
});

it('names an address of a kind this release doesn’t know in words, never failing', function (): void {
    [$source, $run] = OldSite::running('address-clash');
    // A kind a later release adds, with a ULID.
    DB::table('paths')->insert(['path' => 'author/jane-roe', 'target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d', 'is_current' => true]);

    expect(array_map(fn (ImportMessage $message): string => $message->message, acsSaid($source, $run, (new OldSite)->urls('post', [['id' => 2, 'type' => 'page', 'url' => OldSite::HOME.'/author/jane-roe/']]))))
        ->toBe(['/author/jane-roe/ is something this version of Teme doesn’t know (gadget) in Teme, so a page from the old site gets another address.']);
});
