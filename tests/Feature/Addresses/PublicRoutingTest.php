<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
});

it('serves a published post at its address', function (): void {
    addrPublished($this->editor, 'Hello World');

    addrGet('/hello-world/')->assertOk()->assertSee('Hello World');
});

it('sends the address without its trailing slash, and in capitals, to the canonical one', function (): void {
    addrPublished($this->editor, 'Hello World');

    // The test client's get() trims a trailing slash, so each path is sent
    // exactly as written, and the Location header is read as sent.
    expect(addrLocation(addrGet('/hello-world')->assertStatus(301)))->toBe('/hello-world/')
        ->and(addrLocation(addrGet('/Hello-World/')->assertStatus(301)))->toBe('/hello-world/');
});

it('answers an old address with a permanent redirect to the new one', function (): void {
    $post = addrPublished($this->editor, 'Hello World');
    addrRename($post, 'hello-again', $this->editor);

    expect(addrLocation(addrGet('/hello-world/?ref=x')->assertStatus(301)))->toBe('/hello-again/?ref=x');
});

it('answers a draft, a trashed item and an unknown address with 404 in place', function (): void {
    lifecycleDraft($this->editor, 'Secret');

    addrGet('/secret/')->assertNotFound();
    addrGet('/no-such-thing/')->assertNotFound();
});

it('lists a category\'s posts at the category address, paginated', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);

    addrGet('/category/news/')->assertOk();
    expect(addrLocation(addrGet('/category/news/page/1/')->assertStatus(301)))->toBe('/category/news/');
});

it('shows the latest posts on the home page', function (): void {
    addrPublished($this->editor, 'First story');

    addrGet('/')->assertOk()->assertSee('First story');
});

it('sets no cookies on public pages', function (): void {
    addrPublished($this->editor, 'Hello World');

    $response = addrGet('/hello-world/');

    expect($response->headers->getCookies())->toBe([]);
});

it('leaves the admin and health routes alone', function (): void {
    $this->get('/up')->assertOk();
    $this->get('/admin')->assertRedirect();
});

it('shows the chosen page at the site address, and sends that page\'s own address there', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $about = addrPublished($this->editor, 'About us', 'page');
    $news = addrPublished($this->editor, 'News', 'page');

    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $about->id, 'postsPageId' => $news->id]);

    addrGet('/')->assertOk()->assertSee('About us');
    expect(addrLocation(addrGet('/about-us/?ref=x')->assertStatus(301)))->toBe('/?ref=x');
});

it('lists the latest posts at the posts page\'s address, not the page itself', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $about = addrPublished($this->editor, 'About us', 'page');
    $news = addrPublished($this->editor, 'News', 'page');
    addrPublished($this->editor, 'First story');

    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $about->id, 'postsPageId' => $news->id]);

    addrGet('/news/')->assertOk()->assertSee('First story');
    expect(addrLocation(addrGet('/news/page/1/')->assertStatus(301)))->toBe('/news/');
});

it('sends the home page\'s own address to the site address before the recompute has run', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $about = addrPublished($this->editor, 'About us', 'page');

    // The recompute waits on the queue, so /about-us/ is still its current address.
    Queue::fake();
    addrChange($admin, ['frontShows' => 'page', 'frontPageId' => $about->id]);

    expect(addrCurrent('content', $about->id))->toBe('about-us')
        ->and(addrLocation(addrGet('/about-us/?ref=x')->assertStatus(301)))->toBe('/?ref=x');
    addrGet('/page/2/')->assertNotFound();
});
