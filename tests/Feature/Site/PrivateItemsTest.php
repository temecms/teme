<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Comments\Operations\Input\SubmitCommentInput;
use App\Domain\Comments\Operations\SubmitComment;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Site\LatestViews;
use App\Domain\Site\Queries\EditLinks;
use App\Domain\Site\RelatedPosts;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->news = taxonomyTerm('category', 'News');
    // A term has an address once it is projected: the helper's doesn't.
    app(PathProjection::class)->syncTerm($this->news);
    $this->private = Visibilities::change(Visibilities::published($this->editor, 'Zqprivate staff notes', 'zqprivate', [$this->news->id]), 'private', $this->editor);
    $this->protected = Visibilities::change(Visibilities::published($this->editor, 'Members update', 'zqprotected', [$this->news->id]), 'protected', $this->editor, 'Harbour 2024');
    $this->public = Visibilities::published($this->editor, 'Harbour news', 'zqpublic', [$this->news->id]);
});

/**
 * A menu in the default theme's primary place with the private item, the
 * protected one and the home page in it.
 */
function pvMenu(User $editor, string $privateId, string $protectedId): void
{
    $menu = navCreate($editor);
    navSave($editor, $menu, [['kind' => 'content', 'target_id' => $privateId], ['kind' => 'content', 'target_id' => $protectedId], ['kind' => 'home']]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', 'primary', $menu->id, (string) $editor->id));
}

it('shows a private item\'s words, title and address on no public page, while those pages show what is public (I-1700)', function (string $surface): void {
    pvMenu($this->editor, $this->private->id, $this->protected->id);
    // A user has an address once it is projected: the factory's doesn't.
    app(PathProjection::class)->syncUser($this->editor);
    $author = Addresses::relativeUrl($this->editor);
    $uri = match ($surface) {
        'the latest posts' => '/',
        'its category' => '/category/news/',
        'its author' => $author,
        'its year' => '/'.now()->format('Y').'/',
        'the site\'s feed' => '/feed/',
        'its category\'s feed' => '/category/news/feed/',
        'its author\'s feed' => rtrim((string) $author, '/').'/feed/',
        'another item, with its menu and related posts' => '/harbour-news/',
        'its own address' => '/zqprivate-staff-notes/',
    };
    $response = addrGet($uri);
    $html = mb_strtolower((string) $response->getContent());

    expect($html)->not->toContain('zqprivate')
        ->and($html)->not->toContain('zqprotectedbody')
        ->and($html)->not->toContain('zqprotectedexcerpt')
        ->and($html)->not->toContain('zqprotecteddescription');

    // Each page shows what readers may see, so the absence above means something.
    if ($surface === 'its own address') {
        expect($response->getStatusCode())->toBe(404);
    } else {
        expect($response->getStatusCode())->toBe(200)->and($html)->toContain('harbour news');
    }
})->with(['the latest posts', 'its category', 'its author', 'its year', 'the site\'s feed', 'its category\'s feed', 'its author\'s feed', 'another item, with its menu and related posts', 'its own address']);

it('finds neither a private nor a protected item by search, and still finds a public one (I-1700)', function (): void {
    expect(searchIds('zqprivatebody'))->toBe([])
        ->and(searchIds('zqprotectedbody'))->toBe([])
        ->and(searchIds('zqpublicbody'))->toBe([$this->public->id]);
});

it('lists neither a private nor a protected item in the sitemaps, and still lists a public one (I-1700)', function (): void {
    $index = addrGet('/sitemap.xml')->assertOk()->getContent();
    $locs = smLocs((string) addrGet('/sitemap-items-post.xml')->assertOk()->getContent());

    expect($index)->toContain('sitemap-items-post.xml')
        ->and($locs)->toContain(rtrim(url('/'), '/').'/harbour-news/')
        ->and(implode(' ', $locs))->not->toContain('zqprivate')
        ->and(implode(' ', $locs))->not->toContain('members-update');
});

it('keeps the sitemaps\' index to public items, so a page\'s start is still counted from it alone (I-1700)', function (): void {
    $index = (string) DB::table('pg_indexes')->where('tablename', 'contents')->where('indexname', 'contents_sitemap')->value('indexdef');

    expect($index)->toContain('(type, id) INCLUDE (published_at) WHERE')
        ->toContain("'public'")
        ->and(DB::table('pg_indexes')->where('indexname', 'contents_sitemap_next')->exists())->toBeFalse();
});

it('leaves a protected item out of feeds and related posts, and lists it on the site by its title alone (I-1700)', function (): void {
    // A public item under the same category, so the related list is there to leave the protected one out of.
    Visibilities::published($this->editor, 'Market day', 'zqmarket', [$this->news->id]);
    $feed = (string) addrGet('/feed/')->assertOk()->getContent();
    $related = collect(app(RelatedPosts::class)->for($this->public))->map(fn (array $entry): string => $entry['item']->title)->all();
    $card = collect(app(LatestViews::class)->latest(1)->feed->items)->firstWhere('title', 'Members update');

    expect($feed)->not->toContain('Members update')
        ->and($feed)->toContain('Harbour news')
        ->and($related)->toContain('Market day')
        ->and($related)->not->toContain('Members update')
        ->and($card)->not->toBeNull()
        ->and($card?->standfirst)->toBeNull()
        ->and($card?->image)->toBeNull()
        ->and($card?->url)->toBe('/members-update/');
});

it('takes no comment on a private or a protected item, by its Operation too (I-1700)', function (string $which): void {
    $item = $which === 'private' ? $this->private : $this->protected;

    expect(fn () => app(SubmitComment::class)->execute(new SubmitCommentInput(
        contentId: $item->id,
        text: 'Lovely piece.',
        parentId: null,
        name: 'Ada Reader',
        email: 'ada.reader@example.com',
        url: '',
        commenterToken: null,
        ip: '203.0.113.9',
        userAgent: 'Test',
        actorId: null,
    )))->toThrow(ValidationException::class, 'This page takes no comments.');
})->with(['private', 'protected']);

it('answers neither a protected item\'s comment\'s own address nor its reply page, so neither shows its words (I-1700)', function (string $page): void {
    $comment = cmComment($this->protected, attributes: ['author_name' => 'Zqcomment Reader']);
    $response = addrGet($page === 'its address' ? "/_teme/comment/{$comment->id}" : "/_teme/comment/{$comment->id}/reply");

    expect($response->getStatusCode())->toBe(404)
        ->and(mb_strtolower((string) $response->getContent()))->not->toContain('zqcomment');

    // A public item's comment still answers, so the 404 above means something.
    expect(addrGet('/_teme/comment/'.cmComment($this->public)->id)->getStatusCode())->not->toBe(404);
})->with(['its address', 'its reply page']);

it('leaves a private item out of the Edit link, whose path finds nothing there (I-1700)', function (): void {
    $this->actingAs($this->editor);

    expect(app(EditLinks::class)->at((string) $this->editor->id, '/zqprivate-staff-notes/'))->toBeNull()
        ->and(app(EditLinks::class)->at((string) $this->editor->id, '/members-update/'))->not->toBeNull();
});
