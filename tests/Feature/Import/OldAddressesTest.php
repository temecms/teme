<?php

declare(strict_types=1);

use App\Domain\Addresses\Operations\Input\KeepOldAddressesInput;
use App\Domain\Addresses\Operations\KeepOldAddresses;
use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\Addresses\OldAddressesStep;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportPhases;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\OldSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->admin = User::factory()->create()->assignRole('administrator');
    [$this->source, $this->run, $this->token] = OldSite::running('old-addresses');
});

/**
 * KeepOldAddresses as the operator at the server's shell.
 *
 * @param  list<array{path: string, type: string, id: string}>  $addresses
 *
 * @return array{kept: list<string>, held: list<array{path: string, current: bool, type: string, id: string}>}
 */
function oaKeep(array $addresses): array
{
    return scElevated(fn (): array => app(KeepOldAddresses::class)->execute(new KeepOldAddressesInput($addresses, null)));
}

/**
 * The step walked to its end as the operator, its messages recorded as the run's.
 *
 * @return list<ImportMessage>
 */
function oaRun(string $source, string $run, string $token, OldSite $site): array
{
    return scElevated(fn (): array => OldSite::walk(app(OldAddressesStep::class), OldSite::context($source, $run), $site, $token));
}

it('keeps old addresses as history rows by ID, names back those already held, and refuses what can’t be one (I-061)', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    $other = addrPublished($this->admin, 'Ferry fares');

    $result = oaKeep([['path' => '2024/05/harbour-news', 'type' => 'content', 'id' => $post->id], ['path' => 'ferry-fares', 'type' => 'content', 'id' => $post->id]]);

    expect($result)->toBe(['kept' => ['2024/05/harbour-news'], 'held' => [['path' => 'ferry-fares', 'current' => true, 'type' => 'content', 'id' => $other->id]]])
        ->and(addrLocation(addrGet('/2024/05/harbour-news/')))->toBe('/harbour-news/')
        ->and(DB::table('paths')->where('path', '2024/05/harbour-news')->value('is_current'))->toBeFalse()
        ->and(fn () => oaKeep([['path' => 'admin/old', 'type' => 'content', 'id' => $post->id]]))->toThrow(ValidationException::class, 'The address /admin/old/ is used by the site itself.')
        ->and(fn () => oaKeep([['path' => 'gone', 'type' => 'content', 'id' => '01jvm0000000000000000000aa']]))->toThrow(ValidationException::class)
        ->and(fn () => oaKeep([['path' => 'Not/Normal/', 'type' => 'content', 'id' => $post->id]]))->toThrow(ValidationException::class)
        ->and(fn () => oaKeep([]))->toThrow(ValidationException::class);
});

it('writes nothing for someone who may not import', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(KeepOldAddresses::class)->execute(new KeepOldAddressesInput([['path' => 'old', 'type' => 'content', 'id' => $post->id]], (string) $editor->id)))->toThrow(AuthorizationException::class)
        ->and(DB::table('paths')->where('path', 'old')->exists())->toBeFalse();
});

it('keeps each imported category’s, tag’s and author’s old address where Teme now gives it another, leading there by ID (I-339)', function (): void {
    $category = taxonomyTerm('category', 'News');
    $tag = taxonomyTerm('tag', 'Ferries');
    $person = User::factory()->create();
    DB::table('paths')->insert([
        ['path' => 'category/news', 'target_type' => 'term', 'target_id' => $category->id, 'is_current' => true],
        ['path' => 'tag/ferries', 'target_type' => 'term', 'target_id' => $tag->id, 'is_current' => true],
        ['path' => 'author/ada', 'target_type' => 'user', 'target_id' => (string) $person->id, 'is_current' => true],
    ]);
    ImportRecords::entry($this->source, $this->run, 'term', '5', 'created', ['teme_type' => 'term', 'teme_id' => $category->id]);
    ImportRecords::entry($this->source, $this->run, 'term', '6', 'created', ['teme_type' => 'term', 'teme_id' => $tag->id]);
    ImportRecords::entry($this->source, $this->run, 'user', '3', 'updated', ['teme_type' => 'user', 'teme_id' => (string) $person->id]);
    $site = (new OldSite)
        ->urls('post', [['id' => 12, 'type' => 'post', 'url' => OldSite::HOME.'/2024/05/harbour-news.html']])
        ->urls('term', [
            ['id' => 5, 'taxonomy' => 'category', 'url' => OldSite::HOME.'/blog/category/news/'],
            ['id' => 6, 'taxonomy' => 'post_tag', 'url' => OldSite::HOME.'/tag/ferries/'],
            ['id' => 7, 'taxonomy' => 'category', 'url' => OldSite::HOME.'/blog/category/not-brought/'],
        ])
        ->urls('author', [['id' => 3, 'url' => OldSite::HOME.'/blog/author/ada-wp/']]);

    expect(oaRun($this->source, $this->run, $this->token, $site))->toBe([])
        ->and(addrLocation(addrGet('/blog/category/news/')))->toBe('/category/news/')
        ->and(addrLocation(addrGet('/blog/author/ada-wp/')))->toBe('/author/ada/')
        ->and(DB::table('paths')->where('is_current', false)->orderBy('path')->pluck('target_id', 'path')->all())->toBe([
            'blog/author/ada-wp' => (string) $person->id,
            'blog/category/news' => $category->id,
        ])
        // Items' old addresses are written as items are: this step never asks for them.
        ->and(array_values(array_unique(array_map(fn (array $request): string => (string) ($request['query']['kind'] ?? ''), $site->requests))))->toBe(['term', 'author']);
});

it('leaves an old address something else holds, saying whether old links reach the wrong page', function (): void {
    $news = taxonomyTerm('category', 'News');
    $fares = taxonomyTerm('category', 'Fares');
    $timetables = taxonomyTerm('category', 'Timetables');
    DB::table('paths')->insert([
        ['path' => 'category/news', 'target_type' => 'term', 'target_id' => $news->id, 'is_current' => true],
        ['path' => 'category/fares', 'target_type' => 'term', 'target_id' => $fares->id, 'is_current' => true],
        ['path' => 'category/timetables', 'target_type' => 'term', 'target_id' => $timetables->id, 'is_current' => true],
        ['path' => 'topics/timetables', 'target_type' => 'term', 'target_id' => $timetables->id, 'is_current' => false],
    ]);
    ImportRecords::entry($this->source, $this->run, 'term', '5', 'created', ['teme_type' => 'term', 'teme_id' => $news->id]);
    ImportRecords::entry($this->source, $this->run, 'term', '8', 'created', ['teme_type' => 'term', 'teme_id' => $news->id]);
    $site = (new OldSite)->urls('term', [
        ['id' => 5, 'taxonomy' => 'category', 'url' => OldSite::HOME.'/category/fares/'],
        ['id' => 8, 'taxonomy' => 'category', 'url' => OldSite::HOME.'/topics/timetables/'],
    ]);

    $said = oaRun($this->source, $this->run, $this->token, $site);

    expect(array_map(fn (ImportMessage $message): array => [$message->code, $message->level, $message->message], $said))->toBe([
        ['old-address.taken', ImportMessageLevel::Error, '/category/fares/ led to the category "News" on the old site, but here it belongs to the category "Fares", so old links to it reach the wrong page. Change one of the two addresses.'],
        ['old-address.held', ImportMessageLevel::Warning, '/topics/timetables/ led to the category "News" on the old site, but Teme already keeps it as an old address of the category "Timetables", so it leads there.'],
    ]);

    oaRun($this->source, $this->run, $this->token, $site);

    expect(DB::table('import_messages')->where('run_id', $this->run)->orderBy('code')->pluck('code')->all())->toBe(['old-address.held', 'old-address.taken']);
});

it('registers the old addresses step at 350, for every kind of run', function (): void {
    $phase = app(ImportPhases::class)->find('old-addresses');

    expect($phase)->toBeInstanceOf(OldAddressesStep::class)
        ->and($phase?->order())->toBe(350)
        ->and($phase?->label())->toBe('Old addresses')
        ->and($phase?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});

it('names an old address a kind this release doesn’t know holds in words, never failing', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Roe']);
    // A kind a later release adds, with a ULID.
    DB::table('paths')->insert([
        ['path' => 'author/jane-roe', 'target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d', 'is_current' => true],
        ['path' => 'writers/jane-roe', 'target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d', 'is_current' => false],
    ]);
    ImportRecords::entry($this->source, $this->run, 'user', '5', 'created', ['teme_type' => 'user', 'teme_id' => (string) $jane->id]);
    ImportRecords::entry($this->source, $this->run, 'user', '8', 'created', ['teme_type' => 'user', 'teme_id' => (string) $jane->id]);
    $site = (new OldSite)->urls('author', [
        ['id' => 5, 'url' => OldSite::HOME.'/author/jane-roe/'],
        ['id' => 8, 'url' => OldSite::HOME.'/writers/jane-roe/'],
    ]);

    $said = oaRun($this->source, $this->run, $this->token, $site);

    expect(array_map(fn (ImportMessage $message): string => $message->message, $said))->toBe([
        '/author/jane-roe/ led to the author "Jane Roe" on the old site, but here it belongs to something this version of Teme doesn’t know (gadget), so old links to it reach the wrong page. Change one of the two addresses.',
        '/writers/jane-roe/ led to the author "Jane Roe" on the old site, but Teme already keeps it as an old address of something this version of Teme doesn’t know (gadget), so it leads there.',
    ]);
});
