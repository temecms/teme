<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Cache\PageTags;
use App\Domain\People\Credit;
use App\Domain\People\Operations\CreatePerson;
use App\Domain\People\Operations\DeletePerson;
use App\Domain\People\Operations\Input\CreatePersonInput;
use App\Domain\People\Operations\Input\DeletePersonInput;
use App\Domain\People\Operations\Input\UpdatePersonInput;
use App\Domain\People\Operations\UpdatePerson;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->withElevation();
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    $this->grace = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    app(PathProjection::class)->syncUser($this->grace);
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('tags an item\'s page with each credited person\'s list', function (): void {
    $inferred = pageArticle($this->ada, 'Inferred');
    $explicit = pageArticle($this->ada, 'Explicit');
    Credited::set($explicit->id, [Credit::person($this->reuters), Credit::account((int) $this->grace->id)]);

    $footprints = Footprints::contents([$inferred->refresh(), $explicit->refresh()]);

    expect($footprints[$inferred->id])->toContain('author:'.$this->ada->id)
        ->and($footprints[$explicit->id])->toContain('author:'.$this->reuters)
        ->and($footprints[$explicit->id])->toContain('author:'.$this->grace->id)
        ->and($footprints[$explicit->id])->not->toContain('author:'.$this->ada->id);
});

it('tags an account\'s footprint by the account\'s ID and a guest\'s by theirs', function (): void {
    expect(Footprints::person($this->ada))->toEqualCanonicalizing(['person:'.$this->ada->id, 'author:'.$this->ada->id, PageTags::url('author/'.$this->ada->slug)])
        ->and(Footprints::guest($this->reuters))->toEqualCanonicalizing(['person:'.$this->reuters, 'author:'.$this->reuters, PageTags::url('author/reuters')]);
});

it('costs no statement more with the page cache off', function (): void {
    cacheOff($this->folder);
    config(['page_cache.enabled' => false]);
    $article = pageArticle($this->ada, 'Inferred');
    Credited::set($article->id, [Credit::person($this->reuters)]);
    $article->refresh();

    DB::enableQueryLog();

    expect(Footprints::contents([$article]))->toBe([$article->id => []])
        ->and(Footprints::guest($this->reuters))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('purges a page that credits an account by a byline when the account is renamed', function (): void {
    $article = pageArticle($this->ada, 'Explicit');
    Credited::set($article->id, [Credit::person($this->reuters), Credit::account((int) $this->grace->id)]);
    addrGet('/explicit/')->assertOk()->assertSee('Grace Hopper');

    expect(cacheKept('/explicit/'))->toBe(['/explicit/']);

    app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $this->grace->id, 'Grace B. Hopper', $this->grace->email, (string) $this->grace->id));

    expect(cacheKept('/explicit/'))->toBe([]);
});

it('purges the article, the card, the feed, the API item and the .md page when a credited guest is renamed', function (): void {
    Switches::apiOn();
    Switches::llmsOn();
    $article = pageArticle($this->ada, 'Explicit');
    Credited::set($article->id, [Credit::person($this->reuters), Credit::account((int) $this->ada->id)]);
    $pages = ['/explicit/', '/', '/feed/', '/api/v1/items/'.$article->id, '/explicit.md'];

    foreach ($pages as $page) {
        addrGet($page)->assertOk();
    }

    expect(cacheKept(...$pages))->toBe($pages);

    app(UpdatePerson::class)->execute(new UpdatePersonInput((string) $this->admin->id, $this->reuters, name: 'Reuters News'));

    expect(cacheKept(...$pages))->toBe([]);
});

it('names an account by its ID and a person with no account by theirs in every change core reports', function (): void {
    $other = Credited::guest('Associated Press', 'ap');
    cacheShown(['/ada/' => ['person:'.$this->ada->id], '/reuters/' => ['person:'.$this->reuters], '/ap/' => ['person:'.$other], '/new/' => [PageTags::url('author/new-agency')]]);

    app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $this->ada->id, 'Ada King', $this->ada->email, (string) $this->ada->id));

    expect(cacheKept('/ada/', '/reuters/', '/ap/', '/new/'))->toBe(['/reuters/', '/ap/', '/new/']);

    app(UpdatePerson::class)->execute(new UpdatePersonInput((string) $this->admin->id, $this->reuters, name: 'Reuters News'));

    expect(cacheKept('/reuters/', '/ap/', '/new/'))->toBe(['/ap/', '/new/']);

    app(DeletePerson::class)->execute(new DeletePersonInput((string) $this->admin->id, $other));

    expect(cacheKept('/ap/', '/new/'))->toBe(['/new/']);

    app(CreatePerson::class)->execute(new CreatePersonInput((string) $this->admin->id, 'New Agency', 'new-agency'));

    expect(cacheKept('/new/'))->toBe([]);
});
